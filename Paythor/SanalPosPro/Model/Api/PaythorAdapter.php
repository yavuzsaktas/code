<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Model\Api;

use Eticsoft\PaythorClient\PaythorClient;
use Eticsoft\PaythorClient\Models\Payment\Address;
use Eticsoft\PaythorClient\Models\Payment\Cart;
use Eticsoft\PaythorClient\Models\Payment\Create;
use Eticsoft\PaythorClient\Models\Payment\Invoice;
use Eticsoft\PaythorClient\Models\Payment\Order as PaythorOrder;
use Eticsoft\PaythorClient\Models\Payment\Payer;
use Eticsoft\PaythorClient\Models\Payment\Shipping;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;
use Psr\Log\LoggerInterface;

class PaythorAdapter
{
    public function __construct(
        private readonly PaymentConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Verifies a webhook HMAC-SHA256 signature using the stored private key.
     */
    public function verifyWebhookSignature(string $rawBody, string $providedSignature, ?int $storeId = null): bool
    {
        $secret = $this->config->getPrivateKey($storeId);
        if ($secret === '' || $providedSignature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, strtolower(trim($providedSignature)));
    }

    /**
     * Builds an authenticated PaythorClient for the given store.
     */
    private function getClient(int $storeId): PaythorClient
    {
        $publicKey  = $this->config->getPublicKey($storeId);
        $privateKey = $this->config->getPrivateKey($storeId);
        $appId      = $this->config->getAppId($storeId);

        if ($publicKey === '' || $privateKey === '') {
            throw new \RuntimeException(
                'Paythor is not connected. Please complete the Paythor setup in Admin.'
            );
        }

        if ($appId === 0) {
            throw new \RuntimeException(
                'Magento App ID is not set. Please configure it in Admin -> Paythor settings.'
            );
        }

        $client = new PaythorClient([
            'base_url' => PaymentConfig::API_BASE_URL,
        ]);

        $client->setPublicKey($publicKey);
        $client->setPrivateKey($privateKey);
        $client->setProgramId(PaymentConfig::PROGRAM_ID);
        $client->setAppId($appId);

        return $client;
    }

    /**
     * Creates a payment session and returns iframe HTML + transaction data.
     *
     * @return array{iframe_html:string, transaction_id:string, raw:array}
     * @throws \RuntimeException
     */
    public function createPayment(OrderInterface $order, string $callbackUrl): array
    {
        $storeId = (int)$order->getStoreId();
        $client  = $this->getClient($storeId);
        $billing   = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress() ?: $billing;
        $firstName = (string)($billing ? $billing->getFirstname() : $order->getCustomerFirstname());
        $lastName  = (string)($billing ? $billing->getLastname()  : $order->getCustomerLastname());

        // --- Cart ---
        $cart          = new Cart();
        $itemsRowTotal = 0.0;

        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue;
            }
            $qty = (int)$item->getQtyOrdered();
            if ($qty <= 0) {
                continue;
            }

            $rowTotalInclTax = (float)$item->getRowTotalInclTax();
            $discountAmount  = (float)$item->getDiscountAmount();
            $effectiveRow    = max(0.0, $rowTotalInclTax - $discountAmount);
            $effectiveUnit   = $effectiveRow / $qty;

            $itemsRowTotal += $effectiveRow;

            $cart->addItem(
                (string)$item->getSku(),
                (string)$item->getName(),
                'product',
                number_format($effectiveUnit, 2, '.', ''),
                $qty
            );
        }

        $shippingAmount = (float)$order->getShippingInclTax();
        if ($shippingAmount > 0) {
            $cart->addItem('SHIPPING', 'Shipping', 'shipping', number_format($shippingAmount, 2, '.', ''), 1);
        }

        $grandTotal = (float)$order->getGrandTotal();
        $cartSum    = round($itemsRowTotal + $shippingAmount, 2);
        $diff       = round($grandTotal - $cartSum, 2);
        if (abs($diff) >= 0.01) {
            $cart->addItem(
                'ADJUSTMENT',
                $diff > 0 ? 'Fee Adjustment' : 'Discount Adjustment',
                'discount',
                number_format($diff, 2, '.', ''),
                1
            );
        }

        // Paythor requires state for credit card payments on both payer and shipping addresses.
        $payerAddress = $this->buildPaythorAddress($billing ?: $shippingAddress);
        $shipAddress  = $this->buildPaythorAddress($shippingAddress ?: $billing);

        // --- Payer ---
        $payer = new Payer();
        $payer->setFirstName($firstName);
        $payer->setLastName($lastName);
        $payer->setEmail((string)$order->getCustomerEmail());
        $payer->setPhone($billing ? (string)$billing->getTelephone() : '');
        $payer->setAddress($payerAddress);
        $payer->setIp((string)($order->getRemoteIp() ?: $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        // --- Shipping ---
        $shipping = new Shipping();
        $shipping->setFirstName($firstName);
        $shipping->setLastName($lastName);
        $shipping->setPhone($shippingAddress ? (string)$shippingAddress->getTelephone() : '');
        $shipping->setEmail((string)$order->getCustomerEmail());
        $shipping->setAddress($shipAddress);

        // --- Invoice ---
        $invoice = new Invoice();
        $invoice->setId((string)$order->getIncrementId());
        $invoice->setFirstName($firstName);
        $invoice->setLastName($lastName);
        $invoice->setPrice(number_format((float)$order->getGrandTotal(), 2, '.', ''));
        $invoice->setQuantity(1);

        // --- Order ---
        $orderModel = new PaythorOrder();
        $orderModel->setCart($cart);
        $orderModel->setShipping($shipping);
        $orderModel->setInvoice($invoice);

        // --- Create Payment ---
        $create = new Create();
        $create->setAmount(number_format((float)$order->getGrandTotal(), 2, '.', ''));
        $create->setCurrency((string)$order->getOrderCurrencyCode());
        $create->setMethod('creditcard');
        $create->setMerchantReference((string)$order->getIncrementId());
        $create->setReturnUrl($callbackUrl);

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->info('Paythor createPayment request', [
                'order'    => $order->getIncrementId(),
                'amount'   => $order->getGrandTotal(),
                'currency' => $order->getOrderCurrencyCode(),
            ]);
        }

        try {
            $response = $client->payment()->create($create, $payer, $orderModel);
        } catch (\Throwable $e) {
            $this->logger->error('Paythor SDK createPayment failed', [
                'order'   => $order->getIncrementId(),
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Paythor gateway error: ' . $e->getMessage(), 0, $e);
        }

        if (($response['status'] ?? '') === 'error') {
            $details = $response['details'] ?? [];
            $reason = is_array($details) && $details !== []
                ? implode(' | ', array_map(static fn($item): string => (string)$item, $details))
                : (string)($response['message'] ?? 'Unknown gateway error.');

            throw new \RuntimeException('Paythor validation failed: ' . $reason);
        }

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->info('Paythor createPayment response', [
                'order'    => $order->getIncrementId(),
                'response' => $response,
            ]);
        }

        $iframeHtml    = $this->extractIframe($response);
        $transactionId = (string)($response['data']['transaction_id']
            ?? $response['data']['id']
            ?? $response['transaction_id']
            ?? '');

        if ($iframeHtml === '') {
            $this->logger->error('Paythor returned malformed response', [
                'order'    => $order->getIncrementId(),
                'response' => $response,
            ]);
            throw new \RuntimeException('Paythor returned an invalid response.');
        }

        return [
            'iframe_html'    => $iframeHtml,
            'transaction_id' => $transactionId,
            'raw'            => $response,
        ];
    }

    private function buildPaythorAddress($orderAddress): Address
    {
        $street = $orderAddress ? (array)$orderAddress->getStreet() : [];
        $city   = $orderAddress ? (string)$orderAddress->getCity() : '';
        $stateRaw = $orderAddress
            ? (string)($orderAddress->getRegion() ?: $orderAddress->getRegionCode() ?: '')
            : '';

        $address = new Address();
        $address->setCountry($orderAddress ? (string)$orderAddress->getCountryId() : '');
        $address->setCity($city);
        $address->setLine1((string)($street[0] ?? '-'));
        $address->setPostalCode($orderAddress ? (string)$orderAddress->getPostcode() : '');
        $address->setState($this->normalizeRequiredValue($stateRaw, $this->normalizeRequiredValue($city, '-')));

        return $address;
    }

    private function normalizeRequiredValue(string $value, string $fallback): string
    {
        $value = trim($value);
        return $value === '' ? $fallback : $value;
    }

    /**
     * Extracts iframe HTML from the Paythor API response.
     * Handles both direct iframe HTML and payment_link URL cases.
     */
    private function extractIframe(array $response): string
    {
        $data = $response['data'] ?? $response;

        if (!empty($data['iframe'])) {
            return (string)$data['iframe'];
        }

        if (!empty($data['payment_link'])) {
            $url = htmlspecialchars((string)$data['payment_link'], ENT_QUOTES);
            return '<iframe src="' . $url . '" width="100%" height="600" frameborder="0" allowfullscreen></iframe>';
        }

        return '';
    }

    /**
     * Creates a Paythor payment session directly from the active quote (cart),
     * without converting it to a Magento order first.
     * This keeps the cart alive so customers can retry if they abandon the payment.
     *
     * @return array{iframe_html:string, transaction_id:string, raw:array}
     * @throws \RuntimeException
     */
    public function createPaymentFromQuote(CartInterface $quote, string $callbackUrl, string $remoteIp = ''): array
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $storeId      = (int)$quote->getStoreId();
        $client       = $this->getClient($storeId);
        $billing      = $quote->getBillingAddress();
        $shippingAddr = $quote->getShippingAddress() ?: $billing;

        $firstName     = (string)($billing && $billing->getFirstname() ? $billing->getFirstname() : $quote->getCustomerFirstname());
        $lastName      = (string)($billing && $billing->getLastname()  ? $billing->getLastname()  : $quote->getCustomerLastname());
        $customerEmail = (string)($quote->getCustomerEmail() ?: ($billing ? $billing->getEmail() : ''));

        // --- Cart ---
        // Use the effective price per unit (row total incl. tax minus discount divided by qty)
        // so that sum(item_price × qty) + shipping ≤ grandTotal sent as payment amount.
        // Sending original pre-discount prices causes Paythor to reject with
        // "capture.amount cannot be greater than capturable payment amount".
        $cart          = new Cart();
        $itemsRowTotal = 0.0;

        foreach ($quote->getAllVisibleItems() as $item) {
            $qty = (int)$item->getQty();
            if ($qty <= 0) {
                continue;
            }

            $rowTotalInclTax = (float)$item->getRowTotalInclTax(); // qty × price_incl_tax, before discount
            $discountAmount  = (float)$item->getDiscountAmount();   // discount applied to this row
            $effectiveRow    = max(0.0, $rowTotalInclTax - $discountAmount);
            $effectiveUnit   = $effectiveRow / $qty;

            $itemsRowTotal += $effectiveRow;

            $cart->addItem(
                (string)$item->getSku(),
                (string)$item->getName(),
                'product',
                number_format($effectiveUnit, 2, '.', ''),
                $qty
            );
        }

        $shippingAmount = $shippingAddr ? (float)$shippingAddr->getShippingInclTax() : 0.0;
        if ($shippingAmount > 0.0) {
            $cart->addItem('SHIPPING', 'Shipping', 'shipping', number_format($shippingAmount, 2, '.', ''), 1);
        }

        // If rounding leaves a tiny gap between items+shipping and grandTotal,
        // add a small adjustment item so the totals agree inside Paythor.
        $grandTotal = (float)$quote->getGrandTotal();
        $cartSum    = round($itemsRowTotal + $shippingAmount, 2);
        $diff       = round($grandTotal - $cartSum, 2);
        if (abs($diff) >= 0.01) {
            $cart->addItem(
                'ADJUSTMENT',
                $diff > 0 ? 'Fee Adjustment' : 'Discount Adjustment',
                'discount',
                number_format($diff, 2, '.', ''),
                1
            );
        }

        $payerAddress = $this->buildPaythorAddress($billing ?: $shippingAddr);
        $shipAddress  = $this->buildPaythorAddress($shippingAddr ?: $billing);

        // --- Payer ---
        $payer = new Payer();
        $payer->setFirstName($firstName);
        $payer->setLastName($lastName);
        $payer->setEmail($customerEmail);
        $payer->setPhone($billing ? (string)$billing->getTelephone() : '');
        $payer->setAddress($payerAddress);
        $payer->setIp($remoteIp ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        // --- Shipping ---
        $shipping = new Shipping();
        $shipping->setFirstName($firstName);
        $shipping->setLastName($lastName);
        $shipping->setPhone($shippingAddr ? (string)$shippingAddr->getTelephone() : '');
        $shipping->setEmail($customerEmail);
        $shipping->setAddress($shipAddress);

        // --- Invoice ---
        $invoice = new Invoice();
        $invoice->setId((string)$quote->getId());
        $invoice->setFirstName($firstName);
        $invoice->setLastName($lastName);
        $invoice->setPrice(number_format((float)$quote->getGrandTotal(), 2, '.', ''));
        $invoice->setQuantity(1);

        // --- Order model ---
        $orderModel = new PaythorOrder();
        $orderModel->setCart($cart);
        $orderModel->setShipping($shipping);
        $orderModel->setInvoice($invoice);

        // --- Create Payment ---
        $currency = (string)($quote->getQuoteCurrencyCode() ?: 'TRY');
        $amount   = number_format((float)$quote->getGrandTotal(), 2, '.', '');

        $create = new Create();
        $create->setAmount($amount);
        $create->setCurrency($currency);
        $create->setMethod('creditcard');
        $create->setMerchantReference((string)$quote->getId());
        $create->setReturnUrl($callbackUrl);

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->info('Paythor createPaymentFromQuote request', [
                'quote_id' => $quote->getId(),
                'amount'   => $amount,
                'currency' => $currency,
            ]);
        }

        try {
            $response = $client->payment()->create($create, $payer, $orderModel);
        } catch (\Throwable $e) {
            $this->logger->error('Paythor SDK createPaymentFromQuote failed', [
                'quote_id' => $quote->getId(),
                'message'  => $e->getMessage(),
            ]);
            throw new \RuntimeException('Paythor gateway error: ' . $e->getMessage(), 0, $e);
        }

        if (($response['status'] ?? '') === 'error') {
            $details = $response['details'] ?? [];
            $reason = is_array($details) && $details !== []
                ? implode(' | ', array_map(static fn($item): string => (string)$item, $details))
                : (string)($response['message'] ?? 'Unknown gateway error.');
            throw new \RuntimeException('Paythor validation failed: ' . $reason);
        }

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->info('Paythor createPaymentFromQuote response', [
                'quote_id' => $quote->getId(),
                'response' => $response,
            ]);
        }

        $iframeHtml    = $this->extractIframe($response);
        $transactionId = (string)($response['data']['transaction_id']
            ?? $response['data']['id']
            ?? $response['transaction_id']
            ?? '');

        if ($iframeHtml === '') {
            $this->logger->error('Paythor returned malformed response for quote', [
                'quote_id' => $quote->getId(),
                'response' => $response,
            ]);
            throw new \RuntimeException('Paythor returned an invalid response.');
        }

        return [
            'iframe_html'    => $iframeHtml,
            'transaction_id' => $transactionId,
            'raw'            => $response,
        ];
    }

    /**
     * STEP 1 – Sign in with email + password.
     * Uses MAGENTO_APP_ID (105) which is the platform-level ID for Magento on Paythor.
     * Returns the temporary token (status=validation). OTP will be sent to the merchant's email.
     *
     * @return string Temporary token to pass into completeOtpAndSaveKeys()
     * @throws \RuntimeException
     */
    public function initiateLogin(string $email, string $password, string $storeUrl): string
    {
        $appId = PaymentConfig::MAGENTO_APP_ID;
        $stage = $this->config->isSandboxMode() ? 'development' : 'production';

        $client = new PaythorClient(['base_url' => PaymentConfig::API_BASE_URL]);
        $client->setProgramId(PaymentConfig::PROGRAM_ID);
        $client->setAppId($appId);

        $signIn = new \Eticsoft\PaythorClient\Models\Auth\SignIn();
        $signIn->setEmail($email);
        $signIn->setPassword($password);
        $signIn->setProgramId(PaymentConfig::PROGRAM_ID);
        $signIn->setAppId($appId);
        $signIn->setStoreUrl($storeUrl);
        $signIn->setStoreStage($stage);

        $response = $client->auth()->signIn($signIn);

        $token = $response['data']['token_string'] ?? '';
        if ($token === '') {
            $this->logger->warning('Paythor initiateLogin failed', [
                'status'  => $response['status'] ?? 'unknown',
                'message' => $response['message'] ?? 'no message',
            ]);
            throw new \RuntimeException(
                'Login failed: ' . ($response['message'] ?? ($response['details'][0] ?? 'unknown error'))
            );
        }

        $this->logger->info('Paythor initiateLogin success', [
            'user_level'  => $response['data']['user_level'] ?? '',
            'id_merchant' => $response['data']['id_merchant'] ?? '',
        ]);

        return $token;
    }

    /**
     * STEP 2 – Verify OTP, auto-discover the Magento app ID from the platform,
     * install app (if needed), then save API keys — all automatically.
     *
     * @throws \RuntimeException
     */
    public function completeOtpAndSaveKeys(string $tempToken, string $email, string $otp): void
    {
        $client = new PaythorClient(['base_url' => PaymentConfig::API_BASE_URL]);
        $client->setProgramId(PaymentConfig::PROGRAM_ID);
        $client->setAppId(PaymentConfig::MAGENTO_APP_ID);
        $client->setToken($tempToken);

        // 1. Verify OTP
        $otpModel = new \Eticsoft\PaythorClient\Models\Auth\OtpVerify();
        $otpModel->setTarget($email);
        $otpModel->setOtp($otp);

        $otpResponse = $client->auth()->otpVerify($otpModel);

        if (($otpResponse['status'] ?? '') !== 'success') {
            $this->logger->warning('Paythor OTP verify failed', [
                'message' => $otpResponse['message'] ?? 'unknown',
            ]);
            throw new \RuntimeException($otpResponse['message'] ?? 'OTP verification failed.');
        }

        // Replace the temp token with the fully-authenticated token returned after OTP verify.
        // Without this, app install and getApiKeys run under the temp-token merchant context,
        // producing keys whose embedded merchant ID mismatches the access token at the Gateway level.
        $authenticatedToken = $otpResponse['data']['token_string'] ?? '';
        if ($authenticatedToken !== '') {
            $client->setToken($authenticatedToken);
        }

        // 2. Auto-discover the Magento platform app ID from the API
        $appId = $this->discoverMagentoAppId($client);

        // Persist the discovered app ID so getAppId() returns the correct value from now on
        $this->config->saveAppId($appId);
        $client->setAppId($appId);

        $this->logger->info('Paythor discovered Magento app ID', ['app_id' => $appId]);

        // 3. Check if app is already installed for this merchant
        $existingApp = $this->findMyApp($client, $appId);

        // 4. Install app if not yet installed
        $installResponseKeys = null;
        if (empty($existingApp)) {
            $install = new \Eticsoft\PaythorClient\Models\App\Install();
            $install->setAppStage($this->config->isSandboxMode() ? 'development' : 'production');
            $install->setParams([
                'app_id'     => $appId,
                'program_id' => PaymentConfig::PROGRAM_ID,
            ]);
            $installResponse    = $client->app()->install($appId, $install);
            $installResponseKeys = $installResponse['data']['api_keys'] ?? null;
            $existingApp        = $this->findMyApp($client, $appId);
        }

        if (empty($existingApp['id'])) {
            throw new \RuntimeException('Paythor app installation failed. Contact Eticsoft support.');
        }

        // 5. Get API keys — use the install response keys when available to avoid a
        //    secret rotation that occurs if getApiKeys is called on an existing instance,
        //    which would cause the CDN admin's copy of the key to become stale.
        $publicKey  = $installResponseKeys['public_key'] ?? '';
        $privateKey = $installResponseKeys['secret_key'] ?? $installResponseKeys['private_key'] ?? '';

        if ($publicKey === '' || $privateKey === '') {
            $keysResponse = $client->app()->getApiKeys((int)$existingApp['id']);
            $publicKey    = $keysResponse['data']['public_key'] ?? '';
            $privateKey   = $keysResponse['data']['secret_key'] ?? $keysResponse['data']['private_key'] ?? '';
        }

        if ($publicKey === '' || $privateKey === '') {
            throw new \RuntimeException('Paythor did not return API keys.');
        }

        // 6. Save everything automatically — no manual admin input needed
        $this->config->saveCredentials($publicKey, $privateKey, (int)$existingApp['id']);

        $this->logger->info('Paythor connected successfully', [
            'app_id'          => $appId,
            'app_instance_id' => $existingApp['id'],
            'public_key_hint' => substr($publicKey, 0, 12) . '...',
        ]);
    }

    /**
     * Calls /app/list/all and finds the correct app ID for this Magento plugin.
     *
     * The Paythor platform lists app 105 as the Magento SanalPOS PRO entry.
     * We match by name first (future-proof), then fall back to the MAGENTO_APP_ID constant.
     */
    private function discoverMagentoAppId(PaythorClient $client): int
    {
        $response = $client->app()->listAll();

        $keywords = ['magento'];
        foreach (($response['data'] ?? []) as $app) {
            $name = strtolower((string)($app['name'] ?? ''));
            foreach ($keywords as $kw) {
                if (str_contains($name, $kw)) {
                    return (int)$app['id'];
                }
            }
        }

        return PaymentConfig::MAGENTO_APP_ID;
    }

    private function findMyApp(PaythorClient $client, int $appId): array
    {
        $apps = $client->app()->listMy();
        foreach (($apps['data'] ?? []) as $app) {
            if ((int)($app['app_id'] ?? 0) === $appId) {
                return $app;
            }
        }
        return [];
    }
}
