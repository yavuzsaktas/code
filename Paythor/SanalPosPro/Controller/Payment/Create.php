<?php
/**
 * File: app/code/Paythor/SanalPosPro/Controller/Payment/Create.php
 *
 * Custom AJAX endpoint hit by sanalpospro-method.js INSTEAD of the
 * standard Magento REST endpoint /V1/carts/mine/payment-information.
 *
 * Responsibilities:
 *   1. Validate session + form key (CSRF).
 *   2. Force the quote's payment method to paythor_sanalpospro.
 *   3. Submit the quote -> Order via QuoteManagement.
 *   4. Call PaythorAdapter::createPayment() to obtain the iframe HTML.
 *   5. Return JSON { success, iframe_html, order_increment_id }.
 *
 * If the SDK call fails AFTER the order is created, we cancel the order
 * so the customer can re-checkout cleanly.
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
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Paythor\SanalPosPro\Model\Api\PaythorAdapter;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;
use Psr\Log\LoggerInterface;

class Create implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly PaythorAdapter $paythorAdapter,
        private readonly PaymentConfig $paymentConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        // -- 1. CSRF / form key ------------------------------------------------
        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setHttpResponseCode(403)->setData([
                'success' => false,
                'message' => __('Invalid form key. Please refresh the page.')->render(),
            ]);
        }

        // -- 2. Module operational? -------------------------------------------
        if (!$this->paymentConfig->isOperational()) {
            return $result->setHttpResponseCode(503)->setData([
                'success' => false,
                'message' => __('Payment method is not configured.')->render(),
            ]);
        }

        $orderId = null;

        try {
            // -- 3. Load quote from session -----------------------------------
            $quote = $this->checkoutSession->getQuote();
            if (!$quote->getId() || (float)$quote->getGrandTotal() <= 0.0) {
                throw new LocalizedException(__('Your cart is empty or invalid.'));
            }

            // -- 4. Force our payment method on the quote ---------------------
            $quote->getPayment()->setMethod(PaymentConfig::METHOD_CODE);
            $quote->setInventoryProcessed(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            // -- 5. Convert quote -> order ------------------------------------
            $orderId = $this->cartManagement->placeOrder((int)$quote->getId());
            /** @var Order $order */
            $order = $this->orderRepository->get((int)$orderId);

            // Stamp pending payment until the gateway confirms.
            $order->setState(Order::STATE_PENDING_PAYMENT)
                  ->setStatus($this->paymentConfig->getNewOrderStatus() ?: Order::STATE_PENDING_PAYMENT)
                  ->addCommentToStatusHistory(__('Awaiting Paythor SanalPos Pro iframe completion.'));
            $this->orderRepository->save($order);

            // -- 6. Call SDK to obtain iframe HTML ----------------------------
            $callbackUrl = $this->urlBuilder->getUrl(
                'paythor/payment/callback',
                ['_secure' => true]
            );

            $payment = $this->paythorAdapter->createPayment($order, $callbackUrl);

            // Persist Paythor transaction id on the payment record.
            if ($payment['transaction_id'] !== '') {
                $order->getPayment()
                      ->setLastTransId($payment['transaction_id'])
                      ->setTransactionId($payment['transaction_id'])
                      ->setAdditionalInformation('paythor_transaction_id', $payment['transaction_id']);
                $this->orderRepository->save($order);
            }

            // -- 7. Clear quote so the cart is empty post-redirect ------------
            $this->checkoutSession->setLastQuoteId($quote->getId())
                ->setLastSuccessQuoteId($quote->getId())
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus());

            return $result->setData([
                'success'             => true,
                'iframe_html'         => $payment['iframe_html'],
                'order_increment_id'  => $order->getIncrementId(),
            ]);

        } catch (LocalizedException $e) {
            $this->cancelOrderSafely($orderId, $e->getMessage());
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Paythor Create controller failure', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            $this->cancelOrderSafely($orderId, $e->getMessage());
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => __('We could not start the payment. Please try again. 555')->render(),
            ]);
        }
    }

    /**
     * Cancels a partially-created order so the storefront stays consistent
     * if the gateway round-trip fails after order creation.
     */
    private function cancelOrderSafely(?int $orderId, string $reason): void
    {
        if ($orderId === null) {
            return;
        }

        try {
            $this->orderManagement->cancel($orderId);
            $order = $this->orderRepository->get($orderId);
            $order->addCommentToStatusHistory(__('Cancelled automatically: %1', $reason));
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->warning('Paythor: failed to cancel orphan order', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // CsrfAwareActionInterface
    // ------------------------------------------------------------------
    // We DO want CSRF protection on this endpoint, and we already validate
    // the form_key explicitly above. Returning null from both methods tells
    // Magento "use default validation", which on a frontend POST means the
    // request must carry a valid form_key cookie.
    // ------------------------------------------------------------------

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return null;
    }
}
