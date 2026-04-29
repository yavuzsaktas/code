<?php
/**
 * File: app/code/Paythor/SanalPosPro/Controller/Webhook/Notify.php
 *
 * Server-to-server webhook receiver. This is the AUTHORITATIVE fallback
 * for order state transitions when the browser callback could not verify
 * the payment status (network error, transient API failure, etc.).
 *
 * Security:
 *   - Webhook URL is exempt from form_key CSRF (validateForCsrf returns true)
 *     because Paythor cannot post a Magento form key. Authentication is
 *     done via HMAC-SHA256 signature in the X-Paythor-Signature header.
 *   - Signature is verified with hash_equals() (constant-time) inside
 *     PaythorAdapter::verifyWebhookSignature().
 *   - Any failure returns a clean JSON error WITHOUT leaking detail.
 *
 * Idempotency:
 *   - Orders already in PROCESSING or COMPLETE are silently acknowledged
 *     (200 OK) without re-processing, which prevents duplicate invoices
 *     when the browser callback and the webhook both succeed.
 */
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Paythor\SanalPosPro\Model\Api\PaythorAdapter;
use Paythor\SanalPosPro\Model\Order\PaymentStateManager;
use Psr\Log\LoggerInterface;

class Notify implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const SIGNATURE_HEADER = 'X-Paythor-Signature';

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly HttpRequest $request,
        private readonly PaythorAdapter $paythorAdapter,
        private readonly PaymentStateManager $paymentStateManager,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        $rawBody   = (string)$this->request->getContent();
        $signature = (string)$this->request->getHeader(self::SIGNATURE_HEADER);

        // -- 1. Signature verification (HMAC-SHA256 + hash_equals) ------------
        if (!$this->paythorAdapter->verifyWebhookSignature($rawBody, $signature)) {
            $this->logger->warning('Paythor webhook: signature verification FAILED', [
                'remote_ip'     => $this->request->getClientIp(),
                'has_signature' => $signature !== '',
                'body_length'   => strlen($rawBody),
            ]);
            return $result->setHttpResponseCode(401)->setData([
                'success' => false,
                'message' => 'Invalid signature.',
            ]);
        }

        // -- 2. Decode payload ------------------------------------------------
        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('Paythor webhook: malformed JSON', ['error' => $e->getMessage()]);
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => 'Malformed payload.',
            ]);
        }

        $orderInc      = (string)($payload['merchant_order_id'] ?? '');
        $eventStatus   = (string)($payload['status'] ?? '');
        $transactionId = (string)($payload['transaction_id'] ?? '');

        if ($orderInc === '' || $eventStatus === '') {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => 'Missing required fields.',
            ]);
        }

        // -- 3. Locate order --------------------------------------------------
        // Primary: by increment_id (legacy flow).
        // Fallback: by paythor_quote_reference stored in payment additional_information
        //           (new flow where merchant_order_id is the quote_id).
        $order = $this->loadOrderByIncrementId($orderInc)
            ?? $this->loadOrderByQuoteReference($orderInc);

        if ($order === null) {
            $this->logger->warning('Paythor webhook: order not found', ['merchant_order_id' => $orderInc]);
            // Return 200 to avoid retry storms for legitimate unknowns (test pings etc.).
            return $result->setData(['success' => true, 'message' => 'Order not found, ignored.']);
        }

        // -- 4. Idempotency guard --------------------------------------------
        // The browser callback may have already finalised the order via getByToken.
        // Returning 200 here tells Paythor not to retry.
        // CLOSED state covers refunded orders.
        if (in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            return $result->setData([
                'success' => true,
                'message' => 'Order already finalized.',
            ]);
        }

        // -- 5. State machine -------------------------------------------------
        // Normalise to UTF-8 lowercase to match Turkish status strings correctly.
        $normalizedStatus = mb_strtolower(trim($eventStatus), 'UTF-8');

        try {
            if ($this->paythorAdapter->isApprovedStatus($normalizedStatus)) {
                if ($normalizedStatus === 'authorized') {
                    // Authorization only — we must capture to settle the funds.
                    $processToken = (string)($order->getPayment()
                        ->getAdditionalInformation('paythor_process_token') ?? '');

                    $this->paythorAdapter->capturePayment(
                        $processToken,
                        (float)$order->getGrandTotal(),
                        (string)$order->getOrderCurrencyCode(),
                        (int)$order->getStoreId()
                    );
                }

                $this->paymentStateManager->markPaid($order, $transactionId);

            } elseif ($this->paythorAdapter->isFailedStatus($normalizedStatus)) {
                // Başarısız / E Reddedildi / İptal Edildi / declined / cancelled …
                $this->paymentStateManager->markFailed(
                    $order,
                    (string)($payload['message'] ?? $eventStatus)
                );

            } elseif ($this->paythorAdapter->isRefundedStatus($normalizedStatus)) {
                // E İade Edildi / refunded
                $this->paymentStateManager->markRefunded(
                    $order,
                    $transactionId,
                    (string)($payload['message'] ?? '')
                );

            } elseif ($this->paythorAdapter->isPendingStatus($normalizedStatus)) {
                // Başlatıldı / İşleniyor / 3D Güvenli / Planlandı — no state change yet
                $this->paymentStateManager->markPending($order, $eventStatus);

            } else {
                $order->addCommentToStatusHistory(
                    __('Paythor webhook received unrecognised status: %1', $eventStatus)
                );
                $this->orderRepository->save($order);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Paythor webhook processing error', [
                'order'   => $orderInc,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => 'Internal error.',
            ]);
        }

        return $result->setData([
            'success' => true,
            'message' => 'OK',
        ]);
    }

    private function loadOrderByIncrementId(string $incrementId): ?Order
    {
        /** @var Order|false $order */
        $order = $this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->getFirstItem();

        return ($order && $order->getId()) ? $order : null;
    }

    /**
     * Fallback for the new payment flow where merchantReference = quote_id.
     * Callback.php stores the quote_id in payment additional_information under
     * 'paythor_quote_reference' so the webhook can resolve the order here.
     */
    private function loadOrderByQuoteReference(string $quoteRef): ?Order
    {
        if (!ctype_digit($quoteRef)) {
            return null;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table      = $this->resourceConnection->getTableName('sales_order_payment');

            $orderId = $connection->fetchOne(
                $connection->select()
                    ->from($table, ['parent_id'])
                    ->where('additional_information LIKE ?', '%"paythor_quote_reference":"' . $quoteRef . '"%')
                    ->limit(1)
            );

            if (!$orderId) {
                return null;
            }

            return $this->orderRepository->get((int)$orderId);
        } catch (\Throwable $e) {
            $this->logger->warning('Paythor webhook: quote reference lookup failed', [
                'quote_ref' => $quoteRef,
                'message'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
