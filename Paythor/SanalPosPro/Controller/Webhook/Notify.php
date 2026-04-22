<?php
/**
 * File: app/code/Paythor/SanalPosPro/Controller/Webhook/Notify.php
 *
 * Server-to-server webhook receiver. This is the AUTHORITATIVE source
 * of truth for order state transitions — the browser-side Callback
 * controller deliberately does NOT mutate orders.
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
 *   - We refuse to re-process an order that is already PROCESSING/COMPLETE.
 */
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Paythor\SanalPosPro\Model\Api\PaythorAdapter;
use Psr\Log\LoggerInterface;

class Notify implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const SIGNATURE_HEADER = 'X-Paythor-Signature';

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly HttpRequest $request,
        private readonly PaythorAdapter $paythorAdapter,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly InvoiceManagementInterface $invoiceManagement,
        private readonly Transaction $transaction,
        private readonly OrderSender $orderSender,
        private readonly InvoiceSender $invoiceSender,
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
                'remote_ip'      => $this->request->getClientIp(),
                'has_signature'  => $signature !== '',
                'body_length'    => strlen($rawBody),
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
        $order = $this->loadOrderByIncrementId($orderInc);
        if ($order === null) {
            $this->logger->warning('Paythor webhook: order not found', ['order' => $orderInc]);
            // Return 200 to avoid retry storms for orders that legitimately
            // do not exist (test pings etc.).
            return $result->setData(['success' => true, 'message' => 'Order not found, ignored.']);
        }

        // -- 4. Idempotency guard --------------------------------------------
        if (in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
            return $result->setData([
                'success' => true,
                'message' => 'Order already finalized.',
            ]);
        }

        // -- 5. State machine -------------------------------------------------
        try {
            switch (strtolower($eventStatus)) {
                case 'success':
                case 'paid':
                case 'authorized':
                case 'captured':
                    $this->markPaid($order, $transactionId);
                    break;

                case 'failed':
                case 'failure':
                case 'declined':
                case 'cancelled':
                case 'canceled':
                    $this->markFailed($order, $payload['message'] ?? 'Payment declined by gateway.');
                    break;

                default:
                    $order->addCommentToStatusHistory(
                        __('Paythor webhook received unknown status: %1', $eventStatus)
                    );
                    $this->orderRepository->save($order);
                    break;
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

    /**
     * Move order to PROCESSING and create an invoice.
     */
    private function markPaid(Order $order, string $transactionId): void
    {
        $payment = $order->getPayment();
        if ($transactionId !== '') {
            $payment->setTransactionId($transactionId)
                    ->setLastTransId($transactionId)
                    ->setAdditionalInformation('paythor_transaction_id', $transactionId);
        }

        // Create invoice if invoiceable.
        if ($order->canInvoice()) {
            $invoice = $this->invoiceManagement->prepareInvoice($order->getEntityId());
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            $invoice->setTransactionId($transactionId);

            $this->transaction
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();

            try {
                $this->invoiceSender->send($invoice);
            } catch (\Throwable $e) {
                $this->logger->warning('Paythor: invoice email send failed', [
                    'order'   => $order->getIncrementId(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $order->setState(Order::STATE_PROCESSING)
              ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING))
              ->addCommentToStatusHistory(
                  __('Paythor webhook confirmed payment. Transaction: %1', $transactionId ?: 'n/a'),
                  false,
                  true
              );

        $this->orderRepository->save($order);

        try {
            $this->orderSender->send($order);
        } catch (\Throwable $e) {
            $this->logger->warning('Paythor: order email send failed', [
                'order'   => $order->getIncrementId(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel the order on confirmed failure.
     */
    private function markFailed(Order $order, string $reason): void
    {
        if ($order->canCancel()) {
            $this->orderManagement->cancel($order->getEntityId());
            $order = $this->orderRepository->get($order->getEntityId());
        }

        $order->addCommentToStatusHistory(__('Paythor webhook: payment failed (%1).', $reason));
        $this->orderRepository->save($order);
    }

    /**
     * @param string $incrementId
     */
    private function loadOrderByIncrementId(string $incrementId): ?Order
    {
        /** @var Order|false $order */
        $order = $this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->getFirstItem();

        return ($order && $order->getId()) ? $order : null;
    }

    // ------------------------------------------------------------------
    // CsrfAwareActionInterface
    // ------------------------------------------------------------------
    // The webhook is authenticated by HMAC, NOT by Magento's form_key.
    // We must explicitly mark the request as CSRF-valid or Magento will
    // reject it with 403.
    // ------------------------------------------------------------------

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
