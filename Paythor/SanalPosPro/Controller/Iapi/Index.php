<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Iapi;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Paythor\SanalPosPro\Model\Iapi\Handler;

/**
 * Frontend IAPI endpoint consumed by the Paythor CDN app after OTP verification.
 *
 * Loaded from cdn.paythor.com (cross-origin), so it must live on the frontend
 * router — not the admin router — because browsers do not send admin session
 * cookies on cross-origin requests, which would cause Magento to return the
 * HTML login page instead of JSON.
 *
 * Security: every request is validated against the XFVV token (a random
 * sha-256 string generated at module install and injected into the admin page
 * via window.xfvv). Only someone who can load the admin Connect Account page
 * can obtain the token.
 *
 * URL: /paythor/iapi/index  (POST only)
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly JsonFactory $jsonFactory,
        private readonly Handler $handler
    ) {
    }

    public function execute()
    {
        $this->response->setHeader('Access-Control-Allow-Origin', '*', true);
        $this->response->setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS', true);
        $this->response->setHeader('Access-Control-Allow-Headers', 'Content-Type', true);

        $data = $this->handler->run($this->request);
        return $this->jsonFactory->create()->setData($data);
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
