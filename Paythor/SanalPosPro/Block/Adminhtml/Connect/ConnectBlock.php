<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Block\Adminhtml\Connect;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;

class ConnectBlock extends Template
{
    public function __construct(
        Context $context,
        private readonly PaymentConfig $paymentConfig,
        private readonly StatusCollectionFactory $statusCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getIapiUrl(): string
    {
        $base = rtrim($this->_storeManager->getStore()->getBaseUrl(), '/');
        return $base . '/paythor/iapi/index';
    }

    public function getXfvv(): string
    {
        return $this->paymentConfig->getXfvv();
    }

    public function getStoreBaseUrl(): string
    {
        return rtrim((string)$this->_storeManager->getStore()->getBaseUrl(), '/');
    }

    public function getOrderStatusOptions(): array
    {
        $options = [];
        foreach ($this->statusCollectionFactory->create()->load() as $status) {
            $options[(string)$status->getStatus()] = (string)$status->getLabel();
        }
        return $options;
    }

    public function getPaymentConfig(): PaymentConfig
    {
        return $this->paymentConfig;
    }
}
