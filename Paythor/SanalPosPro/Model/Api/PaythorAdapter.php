<?php
/**
 * File: app/code/Paythor/SanalPosPro/Model/Api/PaythorAdapter.php
 *
 * Thin SDK adapter. Bridges Magento's DI/config world to the pure-PHP
 * Eticsoft\Sanalpospro SDK that lives under vendor/Eticsoft/Sanalpospro.
 *
 *  - Constructor injection only (NO ObjectManager).
 *  - Reads config exclusively through PaymentConfig (which itself
 *    wraps ScopeConfigInterface + EncryptorInterface).
 *  - Returns a normalized DTO array so controllers don't depend on
 *    the SDK's response shape.
 */
declare(strict_types=1);

namespace Paythor\SanalPosPro\Model\Api;

use Magento\Sales\Api\Data\OrderInterface;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;
use Psr\Log\LoggerInterface;

class PaythorAdapter
{
    /**
     * @var \Eticsoft\Sanalpospro\Client|null Lazily instantiated SDK client.
     */
    private $client = null;

    public function __construct(
        private readonly PaymentConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns / lazily builds an authenticated SDK client.
     *
     * The SDK is assumed to expose:
     *   new \Eticsoft\Sanalpospro\Client(string $apiKey, string $apiSecret, string $endpoint, bool $sandbox)
     *   ->createPayment(array $payload): array  // returns ['iframe' => '<iframe...>', 'transaction_id' => '...']
     *
     * @return \Eticsoft\Sanalpospro\Client
     * @throws \RuntimeException
     */
    private function getClient(?int $storeId = null)
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $apiKey    = $this->config->getApiKey($storeId);
        $apiSecret = $this->config->getApiSecret($storeId);

        if ($apiKey === '' || $apiSecret === '') {
            throw new \RuntimeException('Paythor SanalPos Pro credentials are not configured.');
        }

        if (!class_exists(\Eticsoft\Sanalpospro\Client::class)) {
            throw new \RuntimeException(
                'Eticsoft Sanalpospro SDK is not installed (vendor/Eticsoft/Sanalpospro).'
            );
        }

        $this->client = new \Eticsoft\Sanalpospro\Client(
            $apiKey,
            $apiSecret,
            $this->config->getApiEndpoint($storeId),
            $this->config->isSandboxMode($storeId)
        );

        return $this->client;
    }

    /**
     * Creates a payment session against Paythor and returns the iframe HTML.
     *
     * @param OrderInterface $order
     * @param string         $callbackUrl Fully-qualified URL Paythor will POST/GET to once
     *                                    the customer completes 3-D Secure.
     * @return array{iframe_html:string, transaction_id:string, raw:array}
     *
     * @throws \RuntimeException When the SDK call fails.
     */
    public function createPayment(OrderInterface $order, string $callbackUrl): array
    {
        $storeId = (int)$order->getStoreId();
        $client  = $this->getClient($storeId);

        $billing = $order->getBillingAddress();

        $payload = [
            'merchant_order_id' => (string)$order->getIncrementId(),
            'amount'            => $this->formatAmount((float)$order->getGrandTotal()),
            'currency'          => (string)$order->getOrderCurrencyCode(),
            'callback_url'      => $callbackUrl,
            'customer'          => [
                'email'      => (string)$order->getCustomerEmail(),
                'first_name' => (string)($billing ? $billing->getFirstname() : $order->getCustomerFirstname()),
                'last_name'  => (string)($billing ? $billing->getLastname()  : $order->getCustomerLastname()),
                'phone'      => $billing ? (string)$billing->getTelephone() : '',
            ],
            'metadata' => [
                'magento_order_id'        => (string)$order->getEntityId(),
                'magento_order_increment' => (string)$order->getIncrementId(),
                'store_id'                => (string)$storeId,
            ],
        ];

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->info('Paythor createPayment request', [
                'order' => $order->getIncrementId(),
                'payload' => $this->redactPayload($payload),
            ]);
        }

        try {
            $response = $client->createPayment($payload);
        } catch (\Throwable $e) {
            $this->logger->error('Paythor SDK createPayment failed', [
                'order'   => $order->getIncrementId(),
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Paythor gateway error: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($response) || empty($response['iframe'])) {
            $this->logger->error('Paythor SDK returned malformed response', [
                'order'    => $order->getIncrementId(),
                'response' => $response,
            ]);
            throw new \RuntimeException('Paythor returned an invalid response.');
        }

        return [
            'iframe_html'    => (string)$response['iframe'],
            'transaction_id' => (string)($response['transaction_id'] ?? ''),
            'raw'            => $response,
        ];
    }

    /**
     * Verifies a webhook HMAC-SHA256 signature using the configured secret.
     *
     * @param string $rawBody             Raw HTTP request body, exactly as received.
     * @param string $providedSignature   Hex digest sent by Paythor in the X-Paythor-Signature header.
     * @param int|null $storeId
     */
    public function verifyWebhookSignature(string $rawBody, string $providedSignature, ?int $storeId = null): bool
    {
        $secret = $this->config->getWebhookSecret($storeId);
        if ($secret === '' || $providedSignature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        // Constant-time comparison to defeat timing attacks.
        return hash_equals($expected, strtolower(trim($providedSignature)));
    }

    /**
     * @param float $amount
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Strips obviously sensitive fields before logging (defense in depth –
     * in practice the SDK payload never contains a PAN because the card
     * data is collected inside Paythor's iframe).
     */
    private function redactPayload(array $payload): array
    {
        unset($payload['card'], $payload['cvv'], $payload['pan']);
        return $payload;
    }
}
