<?php
/**
 * File: app/code/Paythor/SanalPosPro/Controller/Payment/Confirm.php
 *
 * Browser-side AJAX endpoint called by sanalpospro-method.js after Paythor
 * signals a successful payment via the iframe postMessage bridge.
 *
 * This is the point where the Magento order is actually created — mirroring
 * the PrestaShop module's confirmOrder action. The cart (quote) remains active
 * and intact right up until this call succeeds, so customers who abandon the
 * payment or press the browser back button never lose their cart.
 *
 * Responsibilities:
 *   1. Validate form key (CSRF).
 *   2. Verify the reference (quote_id) against the pending ID stored by Create.php.
 *   3. Load the active quote and call placeOrder() → Magento order created here.
 *   4. Stamp the order with pending_payment status and store the quote reference
 *      in payment additional_information so Notify.php (webhook) can find it.
 *   5. Set checkout session data and return { success, redirect_url }.
 *
 * The server-to-server Webhook (Notify.php) remains the authoritative source
 * for moving the order from pending_payment → processing/complete.
 */
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;
use Psr\Log\LoggerInterface;

class Confirm implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentConfig $paymentConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        // -- 1. CSRF / form key -----------------------------------------------
        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setHttpResponseCode(403)->setData([
                'success' => false,
                'message' => __('Invalid form key.')->render(),
            ]);
        }

        try {
            // -- 2. Validate reference against session ------------------------
            $reference = trim((string)$this->request->getParam('reference', ''));

            if ($reference === '' || !ctype_digit($reference)) {
                throw new LocalizedException(__('Invalid payment reference.'));
            }

            $pendingQuoteId = (int)$this->checkoutSession->getPaythorPendingQuoteId();

            if ($pendingQuoteId === 0 || $pendingQuoteId !== (int)$reference) {
                $this->logger->warning('Paythor Confirm: session/reference mismatch', [
                    'reference'       => $reference,
                    'pending_quote_id' => $pendingQuoteId,
                ]);
                throw new LocalizedException(__('Payment session mismatch. Please try again.'));
            }

            // -- 3. Load active quote -----------------------------------------
            $quote = $this->cartRepository->getActive($pendingQuoteId);

            if (!$this->isValidQuote($quote)) {
                throw new LocalizedException(__('Your cart is empty or has already been processed.'));
            }

            // -- 4. Convert quote → order (happens here, not in Create.php) ---
            $orderId = $this->cartManagement->placeOrder((int)$quote->getId());
            /** @var Order $order */
            $order = $this->orderRepository->get((int)$orderId);

            $order->setState(Order::STATE_PENDING_PAYMENT)
                  ->setStatus($this->paymentConfig->getNewOrderStatus() ?: Order::STATE_PENDING_PAYMENT)
                  ->addCommentToStatusHistory(__('Paythor payment confirmed by browser. Awaiting server webhook.'));

            // Store the quote_id so Notify.php (webhook) can find this order
            // when merchant_order_id from Paythor equals the original quote_id.
            $order->getPayment()
                  ->setAdditionalInformation('paythor_quote_reference', (string)$pendingQuoteId);

            $this->orderRepository->save($order);

            // -- 5. Clear pending ID + set checkout session -------------------
            $this->checkoutSession->unsPaythorPendingQuoteId();

            $this->checkoutSession
                ->setLastQuoteId($quote->getId())
                ->setLastSuccessQuoteId($quote->getId())
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus());

            $this->logger->info('Paythor Confirm: order created', [
                'quote_id'  => $pendingQuoteId,
                'order_id'  => $order->getIncrementId(),
            ]);

            return $result->setData([
                'success'      => true,
                'redirect_url' => $this->urlBuilder->getUrl('checkout/onepage/success'),
            ]);

        } catch (LocalizedException $e) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Paythor Confirm controller failure', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => __('Could not finalize the order. Please contact support.')->render(),
            ]);
        }
    }

    private function isValidQuote(?CartInterface $quote): bool
    {
        return $quote !== null
            && (int)$quote->getId() > 0
            && (float)$quote->getGrandTotal() > 0.0;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return null;
    }
}
