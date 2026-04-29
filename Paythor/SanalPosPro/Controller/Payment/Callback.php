<?php
/**
 * File: app/code/Paythor/SanalPosPro/Controller/Payment/Callback.php
 *
 * Handles two distinct cases depending on how Paythor calls back:
 *
 * Case A — Full-browser redirect (p_id present):
 *   Paythor navigates window.top.location to this URL after payment, appending
 *   "?p_id=<process_token>".  We create the Magento order from the pending
 *   quote, then call Paythor's process/getbytoken API to read the definitive
 *   payment status:
 *     - approved  → invoice created, order moves to PROCESSING, success page
 *     - declined  → order cancelled, customer redirected back to cart
 *     - unknown   → order left in PENDING_PAYMENT; the server webhook finalises
 *                   it asynchronously (fallback for transient API errors)
 *
 * Case B — postMessage bridge (no p_id):
 *   The iframe itself was redirected here.  We render a tiny HTML page whose
 *   <script> calls window.parent.postMessage so that sanalpospro-method.js
 *   can close the modal and redirect the customer.
 *
 * No CSRF token is required: this is a GET-only browser-return URL.
 * Order creation is authenticated by the server-side checkout session
 * (paythorPendingQuoteId) which only the real user's browser can hold.
 */
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Paythor\SanalPosPro\Model\Api\PaythorAdapter;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;
use Paythor\SanalPosPro\Model\Order\PaymentStateManager;
use Psr\Log\LoggerInterface;

class Callback implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentConfig $paymentConfig,
        private readonly PaythorAdapter $paythorAdapter,
        private readonly PaymentStateManager $paymentStateManager,
        private readonly MessageManager $messageManager,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return ResultInterface|ResponseInterface
     */
    public function execute()
    {
        $p_id = trim((string)$this->request->getParam('p_id', ''));

        // Fallback: Paythor appends &p_id=... (no leading ?) when the return_url
        // has no query string, making PHP's $_GET miss the parameter.
        // Parse REQUEST_URI directly to handle both ?p_id=... and &p_id=... forms.
        if ($p_id === '') {
            $requestUri = (string)($this->request->getServer('REQUEST_URI') ?? '');
            $p_id = $this->extractProcessTokenFromRequestUri($requestUri);

            if ($p_id !== '') {
                $this->logger->info('Paythor Callback: p_id recovered from REQUEST_URI fallback', [
                    'request_uri' => $requestUri,
                ]);
            }
        }

        if ($p_id !== '') {
            return $this->handleFullBrowserRedirect($p_id);
        }

        return $this->handlePostMessageBridge();
    }

    /**
     * Extract p_id from malformed or standard callback URLs.
     */
    private function extractProcessTokenFromRequestUri(string $requestUri): string
    {
        if ($requestUri === '') {
            return '';
        }

        if (preg_match('/[?&]p_id=([^&]+)/', $requestUri, $m) === 1) {
            return trim(rawurldecode((string)$m[1]));
        }

        return '';
    }

    /**
     * Case A — Paythor used window.top.location redirect.
     *
     * Flow:
     *  1. Create Magento order from pending quote.
     *  2. Call process/getbytoken to get the real payment status.
     *  3a. Approved  → markPaid (invoice + PROCESSING) → success page.
     *  3b. Declined  → markFailed (cancel) → cart page with error message.
     *  3c. Unknown   → leave PENDING_PAYMENT → success page (webhook finalises).
     */
    private function handleFullBrowserRedirect(string $processToken): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        try {
            $pendingQuoteId = (int)$this->checkoutSession->getPaythorPendingQuoteId();

            if ($pendingQuoteId === 0) {
                $this->logger->warning('Paythor Callback: no pending quote in session', [
                    'process_token' => substr($processToken, 0, 16) . '...',
                ]);
                return $redirect->setPath('checkout/cart');
            }

            $quote = $this->cartRepository->getActive($pendingQuoteId);

            if (!$quote || (int)$quote->getId() === 0 || (float)$quote->getGrandTotal() <= 0) {
                $this->logger->warning('Paythor Callback: pending quote not found or empty', [
                    'pending_quote_id' => $pendingQuoteId,
                ]);
                return $redirect->setPath('checkout/cart');
            }

            // -- 1. Convert quote → order (cart is still active up to this point) --
            $orderId = $this->cartManagement->placeOrder((int)$quote->getId());
            /** @var Order $order */
            $order = $this->orderRepository->get((int)$orderId);

            // Store the process token so the webhook can cross-reference it if needed.
            $order->getPayment()
                  ->setAdditionalInformation('paythor_quote_reference', (string)$pendingQuoteId)
                  ->setAdditionalInformation('paythor_process_token', $processToken);

            $order->setState(Order::STATE_PENDING_PAYMENT)
                  ->setStatus($this->paymentConfig->getNewOrderStatus() ?: Order::STATE_PENDING_PAYMENT);

            $this->orderRepository->save($order);

            // Update checkout session so the success page renders correctly
            // regardless of the payment status outcome below.
            $this->checkoutSession->unsPaythorPendingQuoteId();
            $this->checkoutSession
                ->setLastQuoteId($quote->getId())
                ->setLastSuccessQuoteId($quote->getId())
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus());

            $this->logger->info('Paythor Callback: order created, verifying payment status', [
                'quote_id'      => $pendingQuoteId,
                'order_id'      => $order->getIncrementId(),
                'process_token' => substr($processToken, 0, 16) . '...',
            ]);

            // -- 2. Query Paythor for the definitive payment status --
            $storeId       = (int)$order->getStoreId();
            $processResult = $this->paythorAdapter->getProcessStatus($processToken, $storeId);

            // -- 3a. Payment approved → create invoice, move to PROCESSING --
            if ($processResult['is_approved']) {
                $this->paymentStateManager->markPaid($order, $processResult['transaction_id']);

                $this->logger->info('Paythor Callback: payment approved via getByToken', [
                    'order_id'       => $order->getIncrementId(),
                    'transaction_id' => $processResult['transaction_id'],
                ]);

                return $redirect->setPath('checkout/onepage/success');
            }

            // -- 3b. Payment definitively declined → cancel order, back to cart --
            if ($processResult['is_failed']) {
                $reason = (string)($processResult['raw']['data']['message']
                    ?? $processResult['raw']['message']
                    ?? $processResult['status']);

                $this->paymentStateManager->markFailed($order, $reason);

                $this->logger->info('Paythor Callback: payment declined via getByToken', [
                    'order_id' => $order->getIncrementId(),
                    'reason'   => $reason,
                ]);

                $this->messageManager->addErrorMessage(
                    __('Your payment was declined. Please try again or use a different payment method.')
                );

                return $redirect->setPath('checkout/cart');
            }

            // -- 3c. Status unknown (pending or API error) --
            // Keep the order in PENDING_PAYMENT; the server webhook will finalise it.
            // The customer still sees the success page — the order exists and is real.
            $order->addCommentToStatusHistory(
                __('Paythor: browser-redirect callback received. Awaiting server webhook for final confirmation. (process status: %1)', $processResult['status'])
            );
            $this->orderRepository->save($order);

            $this->logger->info('Paythor Callback: payment status indeterminate, awaiting webhook', [
                'order_id' => $order->getIncrementId(),
                'status'   => $processResult['status'],
            ]);

            return $redirect->setPath('checkout/onepage/success');

        } catch (\Throwable $e) {
            $this->logger->error('Paythor Callback: full-browser redirect handling failed', [
                'process_token' => substr($processToken, 0, 16) . '...',
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);
            return $redirect->setPath('checkout/cart');
        }
    }

    /**
     * Case B — postMessage bridge for iframe-based redirect (no p_id).
     * Renders a tiny HTML script that posts a message to the parent window.
     */
    private function handlePostMessageBridge(): ResultInterface
    {
        $status  = (string)$this->request->getParam('status', 'failure');
        $ref     = (string)$this->request->getParam('order', '');
        $message = (string)$this->request->getParam('message', '');

        $status = in_array($status, ['success', 'failure', 'cancel'], true) ? $status : 'failure';

        $this->logger->info('Paythor callback postMessage bridge', [
            'status'    => $status,
            'reference' => $ref,
        ]);

        $payload = [
            'source'    => 'paythor_sanalpospro',
            'status'    => $status,
            'reference' => $ref,
            'message'   => $message,
        ];

        $successUrl = $this->urlBuilder->getUrl('checkout/onepage/success', ['_secure' => true]);
        $failureUrl = $this->urlBuilder->getUrl('checkout/cart', ['_secure' => true]);

        $json           = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $successUrlJson = json_encode($successUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $failureUrlJson = json_encode($failureUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Processing…</title>
    <meta name="robots" content="noindex,nofollow">
</head>
<body>
<script>
    (function () {
        var payload = {$json};
        var successUrl = {$successUrlJson};
        var failureUrl = {$failureUrlJson};

        var targetUrl = payload.status === 'success' ? successUrl : failureUrl;

        function redirectTopLevel() {
            window.location.replace(targetUrl);
        }

        try {
            var posted = false;

            if (window.parent && window.parent !== window) {
                window.parent.postMessage(payload, window.location.origin);
                posted = true;
            }

            if (window.opener && window.opener !== window && !window.opener.closed) {
                window.opener.postMessage(payload, window.location.origin);
                posted = true;

                try {
                    window.close();
                } catch (closeErr) {
                    // Some browsers block window.close().
                }

                window.setTimeout(function () {
                    if (!window.closed) {
                        redirectTopLevel();
                    }
                }, 400);
            }

            if (!posted) {
                redirectTopLevel();
            }
        } catch (e) {
            redirectTopLevel();
        }
    })();
</script>
<noscript>Please return to the previous page.</noscript>
</body>
</html>
HTML;

        return $this->rawFactory->create()
            ->setHeader('Content-Type', 'text/html; charset=UTF-8', true)
            ->setHeader('X-Frame-Options', 'SAMEORIGIN', true)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true)
            ->setContents($html);
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
