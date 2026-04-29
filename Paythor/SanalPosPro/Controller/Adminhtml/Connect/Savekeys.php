<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;

/**
 * AJAX endpoint called by the client-side connect flow.
 * Receives the API keys already fetched by the browser and persists them.
 */
class Savekeys extends Action
{
    public const ADMIN_RESOURCE = 'Paythor_SanalPosPro::connect';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly PaymentConfig $paymentConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Pool $cacheFrontendPool
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        $publicKey     = trim((string)$this->getRequest()->getPost('public_key'));
        $privateKey    = trim((string)$this->getRequest()->getPost('secret_key'));
        $appInstanceId = (int)$this->getRequest()->getPost('app_instance_id');
        $appId         = (int)$this->getRequest()->getPost('app_id');

        if ($publicKey === '' || $privateKey === '') {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => 'Missing API keys.',
            ]);
        }

        try {
            $this->paymentConfig->saveCredentials($publicKey, $privateKey, $appInstanceId);

            // Always enforce Magento platform app ID to avoid any mismatched app_id
            // coming from CDN-side auto-discovery across multi-platform accounts.
            $this->paymentConfig->saveAppId(PaymentConfig::MAGENTO_APP_ID);

            $this->cacheTypeList->cleanType('config');
            foreach ($this->cacheFrontendPool as $cacheFrontend) {
                $cacheFrontend->getBackend()->clean();
            }

            return $result->setData(['success' => true]);

        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
