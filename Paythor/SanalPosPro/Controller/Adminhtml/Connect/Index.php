<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Paythor_SanalPosPro::connect';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly PaymentConfig $paymentConfig
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Paythor – Connect Your Account'));

        if ($this->paymentConfig->isConnected()) {
            $this->messageManager->addSuccessMessage(
                __('Paythor is connected. Public Key: %1...', substr($this->paymentConfig->getPublicKey(), 0, 20))
            );
        }

        return $page;
    }
}
