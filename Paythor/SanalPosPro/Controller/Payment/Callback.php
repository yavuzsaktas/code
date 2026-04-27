<?php
/**
 * File: app/code/Paythor/SanalPosPro/Controller/Payment/Callback.php
 *
 * Handles two distinct cases depending on how Paythor calls back:
 *
 * Case A — Full-browser redirect (p_id present):
 *   Paythor navigates window.top.location to this URL after payment, appending
 *   "?p_id=<process_token>".  Since the full browser is here, postMessage to a
 *   parent frame is impossible.  We create the Magento order directly from the
 *   pending quote stored in the checkout session, then redirect to the success
 *   or failure page.
 *
 * Case B — postMessage bridge (no p_id):
 *   The iframe itself was redirected here (no top-level navigation).  We render
 *   a tiny HTML page whose <script> calls window.parent.postMessage so that
 *   sanalpospro-method.js can close the modal and redirect the customer.
 *   This path is a fallback — Paythor may not use it, but we keep it for
 *   forward-compatibility.
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
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;
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
            // Case A: Paythor navigated the full browser here with a process token.
            return $this->handleFullBrowserRedirect($p_id);
        }

        // Case B: postMessage bridge (iframe redirect without p_id).
        return $this->handlePostMessageBridge();
    }

    /**
     * Extract p_id from malformed or standard callback URLs.
     * Supports both '?p_id=...' and '&p_id=...' styles.
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
     * Create the order from session and redirect to success/cart.
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

            // Convert quote → order (the cart is still active up to this point).
            $orderId = $this->cartManagement->placeOrder((int)$quote->getId());
            /** @var Order $order */
            $order = $this->orderRepository->get((int)$orderId);

            $order->setState(Order::STATE_PENDING_PAYMENT)
                  ->setStatus($this->paymentConfig->getNewOrderStatus() ?: Order::STATE_PENDING_PAYMENT)
                  ->addCommentToStatusHistory(
                      __('Paythor: browser-redirect callback received (p_id). Awaiting server webhook.')
                  );

            // Store both the quote reference and the process token so the webhook
            // can find this order by quote_id and optionally log the process token.
            $order->getPayment()
                  ->setAdditionalInformation('paythor_quote_reference', (string)$pendingQuoteId)
                  ->setAdditionalInformation('paythor_process_token', $processToken);

            $this->orderRepository->save($order);

            // Update checkout session so the success page renders correctly.
            $this->checkoutSession->unsPaythorPendingQuoteId();
            $this->checkoutSession
                ->setLastQuoteId($quote->getId())
                ->setLastSuccessQuoteId($quote->getId())
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus());

            $this->logger->info('Paythor Callback: order created via browser redirect', [
                'quote_id' => $pendingQuoteId,
                'order_id' => $order->getIncrementId(),
            ]);

            return $redirect->setPath('checkout/onepage/success');

        } catch (\Throwable $e) {
            $this->logger->error('Paythor Callback: full-browser confirm failed', [
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

        // 'reference' carries the quote_id (merchantReference set in Create.php).
        $payload = [
            'source'    => 'paythor_sanalpospro',
            'status'    => $status,
            'reference' => $ref,
            'message'   => $message,
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

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
        try {
            var payload = {$json};
            if (window.parent && window.parent !== window) {
                window.parent.postMessage(payload, window.location.origin);
            }
        } catch (e) {
            // Swallow — parent will time out and surface a generic error.
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
