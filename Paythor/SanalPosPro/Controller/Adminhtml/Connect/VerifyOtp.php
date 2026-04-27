<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Cache\Frontend\Pool;
use Paythor\SanalPosPro\Model\Api\PaythorAdapter;

/**
 * Step 2 POST: verifies OTP, installs app if needed, saves API keys.
 */
class VerifyOtp extends Action
{
    public const ADMIN_RESOURCE = 'Paythor_SanalPosPro::connect';

    public function __construct(
        Context $context,
        private readonly PaythorAdapter $paythorAdapter,
        private readonly Session $backendSession,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Pool $cacheFrontendPool
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect  = $this->resultRedirectFactory->create();
        $otp       = trim((string)$this->getRequest()->getPost('otp_code'));
        $tempToken = (string)$this->backendSession->getPaythorTempToken();
        $email     = (string)$this->backendSession->getPaythorTempEmail();

        if ($tempToken === '' || $email === '') {
            $this->messageManager->addErrorMessage(__('Session expired. Please log in again.'));
            return $redirect->setPath('paythor_sanalpospro/connect/index');
        }

        if ($otp === '') {
            $this->messageManager->addErrorMessage(__('OTP code is required.'));
            return $redirect->setPath('paythor_sanalpospro/connect/otp');
        }

        try {
            $this->paythorAdapter->completeOtpAndSaveKeys($tempToken, $email, $otp);

            // Clear temp session data
            $this->backendSession->unsPaythorTempToken();
            $this->backendSession->unsPaythorTempEmail();

            // Flush config cache so new keys are picked up immediately
            $this->cacheTypeList->cleanType('config');
            foreach ($this->cacheFrontendPool as $cacheFrontend) {
                $cacheFrontend->getBackend()->clean();
            }

            $this->messageManager->addSuccessMessage(
                __('Paythor connected successfully! API keys have been saved automatically.')
            );
            return $redirect->setPath('paythor_sanalpospro/connect/index');

        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Error: %1', $e->getMessage()));
            return $redirect->setPath('paythor_sanalpospro/connect/otp');
        }
    }
}
