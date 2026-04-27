<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session;
use Magento\Framework\Controller\Result\RedirectFactory;
use Paythor\SanalPosPro\Model\Api\PaythorAdapter;

/**
 * Step 1: receives email + password, calls Paythor signin, stores temp token in session,
 * then redirects to the OTP verification page.
 */
class Save extends Action
{
    public const ADMIN_RESOURCE = 'Paythor_SanalPosPro::connect';

    public function __construct(
        Context $context,
        private readonly PaythorAdapter $paythorAdapter,
        private readonly Session $backendSession
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();

        $email    = trim((string)$this->getRequest()->getPost('paythor_email'));
        $password = trim((string)$this->getRequest()->getPost('paythor_password'));
        $storeUrl = trim((string)$this->getRequest()->getPost('store_url'));

        if ($email === '' || $password === '') {
            $this->messageManager->addErrorMessage(__('Email and password are required.'));
            return $redirect->setPath('paythor_sanalpospro/connect/index');
        }

        try {
            $tempToken = $this->paythorAdapter->initiateLogin($email, $password, $storeUrl);

            // Store temp data in session for step 2
            $this->backendSession->setPaythorTempToken($tempToken);
            $this->backendSession->setPaythorTempEmail($email);

            $this->messageManager->addSuccessMessage(
                __('An OTP code has been sent to %1. Please enter it below.', $email)
            );

            return $redirect->setPath('paythor_sanalpospro/connect/otp');

        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Login failed: %1', $e->getMessage()));
            return $redirect->setPath('paythor_sanalpospro/connect/index');
        }
    }
}
