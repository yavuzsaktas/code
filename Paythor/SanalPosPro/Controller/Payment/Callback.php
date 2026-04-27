<?php
/**
 * File: app/code/Paythor/SanalPosPro/Controller/Payment/Callback.php
 *
 * Browser-facing return URL for the Paythor iframe.
 *
 * Paythor redirects the iframe (top-of-iframe, not top-of-window) to:
 *     <base_url>paythor/payment/callback?status=success&order=000000123&...
 *
 * This controller does NOT mutate order state — that is the webhook's
 * job (server-to-server, signed). Its sole responsibility is to render
 * a tiny HTML bridge that posts a message to the parent window so
 * sanalpospro-method.js can close the modal and redirect the customer.
 *
 * No CSRF token is required because nothing is mutated. We DO require
 * GET to keep things explicit.
 */
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

class Callback implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly RequestInterface $request,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return ResultInterface|ResponseInterface
     */
    public function execute()
    {
        $status     = (string)$this->request->getParam('status', 'failure');
        $orderInc   = (string)$this->request->getParam('order', '');
        $message    = (string)$this->request->getParam('message', '');

        // Whitelist status to avoid arbitrary string injection through
        // the postMessage payload.
        $status = in_array($status, ['success', 'failure', 'cancel'], true) ? $status : 'failure';

        $this->logger->info('Paythor callback bridge', [
            'status' => $status,
            'order'  => $orderInc,
        ]);

        $payload = [
            'source'             => 'paythor_sanalpospro',
            'status'             => $status,
            'order_increment_id' => $orderInc,
            'message'            => $message,
        ];

        $successUrl = $this->urlBuilder->getUrl('checkout/onepage/success', ['_secure' => true]);
        $failureUrl = $this->urlBuilder->getUrl('checkout/cart', ['_secure' => true]);

        // Encode for safe embedding inside <script>.
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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

    // CSRF is not applicable to a GET-only bridge.
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}