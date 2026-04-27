<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Model\Order;

use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

/**
 * Shared service for Paythor order state transitions.
 *
 * Centralises markPaid() and markFailed() so both the browser Callback
 * controller and the server Webhook controller apply identical logic.
 * Both callers rely on the idempotency guard at the top of markPaid()
 * to avoid duplicate invoice creation when they race each other.
 */
class PaymentStateManager
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly InvoiceManagementInterface $invoiceManagement,
        private readonly TransactionFactory $transactionFactory,
        private readonly OrderSender $orderSender,
        private readonly InvoiceSender $invoiceSender,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Creates a capture invoice and moves the order to PROCESSING.
     *
     * Safe to call from both the browser callback and the server webhook.
     * If the order is already PROCESSING or COMPLETE (e.g. the webhook
     * arrived first), the method returns early without touching anything.
     */
    public function markPaid(Order $order, string $transactionId): void
    {
        if (in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
            $this->logger->info('Paythor PaymentStateManager: order already finalized, markPaid skipped', [
                'order' => $order->getIncrementId(),
                'state' => $order->getState(),
            ]);
            return;
        }

        $payment = $order->getPayment();
        if ($transactionId !== '') {
            $payment->setTransactionId($transactionId)
                    ->setLastTransId($transactionId)
                    ->setAdditionalInformation('paythor_transaction_id', $transactionId);
        }

        if ($order->canInvoice()) {
            $invoice = $this->invoiceManagement->prepareInvoice($order->getEntityId());
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            $invoice->setTransactionId($transactionId);

            // Use a fresh Transaction object every time to avoid stale state
            // if markPaid is ever called more than once per process.
            $this->transactionFactory->create()
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();

            try {
                $this->invoiceSender->send($invoice);
            } catch (\Throwable $e) {
                $this->logger->warning('Paythor: invoice email send failed (non-fatal)', [
                    'order'   => $order->getIncrementId(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $order->setState(Order::STATE_PROCESSING)
              ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING))
              ->addCommentToStatusHistory(
                  __('Paythor: payment confirmed. Transaction ID: %1', $transactionId ?: 'n/a'),
                  false,
                  true
              );

        $this->orderRepository->save($order);

        try {
            $this->orderSender->send($order);
        } catch (\Throwable $e) {
            $this->logger->warning('Paythor: order email send failed (non-fatal)', [
                'order'   => $order->getIncrementId(),
                'message' => $e->getMessage(),
            ]);
        }

        $this->logger->info('Paythor PaymentStateManager: order moved to PROCESSING', [
            'order'          => $order->getIncrementId(),
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * Cancels the order on a confirmed payment failure.
     *
     * Safe to call even when the order has already been cancelled
     * (canCancel() guards against the double-cancel).
     */
    public function markFailed(Order $order, string $reason): void
    {
        if ($order->canCancel()) {
            $this->orderManagement->cancel($order->getEntityId());
            $order = $this->orderRepository->get($order->getEntityId());
        }

        $order->addCommentToStatusHistory(__('Paythor: payment failed — %1', $reason));
        $this->orderRepository->save($order);

        $this->logger->info('Paythor PaymentStateManager: order cancelled due to failed payment', [
            'order'  => $order->getIncrementId(),
            'reason' => $reason,
        ]);
    }
}
