<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;

class PaymentConfig
{
    public const METHOD_CODE     = 'paythor_sanalpospro';
    public const PROGRAM_ID      = 1;
    public const API_BASE_URL    = 'https://live-api.sanalpospro.com';
    public const MAGENTO_APP_ID  = 105;

    private const XML_PATH_PREFIX = 'payment/' . self::METHOD_CODE . '/';

    public const XML_PATH_ACTIVE          = self::XML_PATH_PREFIX . 'active';
    public const XML_PATH_TITLE           = self::XML_PATH_PREFIX . 'title';
    public const XML_PATH_SANDBOX_MODE    = self::XML_PATH_PREFIX . 'sandbox_mode';
    public const XML_PATH_PAYMENT_ACTION  = self::XML_PATH_PREFIX . 'payment_action';
    public const XML_PATH_ORDER_STATUS    = self::XML_PATH_PREFIX . 'order_status';
    public const XML_PATH_DEBUG           = self::XML_PATH_PREFIX . 'debug';
    public const XML_PATH_APP_ID          = self::XML_PATH_PREFIX . 'app_id';

    /** Auto-saved – never entered manually */
    public const XML_PATH_PUBLIC_KEY      = self::XML_PATH_PREFIX . 'public_key';
    public const XML_PATH_PRIVATE_KEY     = self::XML_PATH_PREFIX . 'private_key';
    public const XML_PATH_APP_INSTANCE_ID = self::XML_PATH_PREFIX . 'app_instance_id';
    public const XML_PATH_XFVV           = self::XML_PATH_PREFIX . 'xfvv';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ACTIVE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getTitle(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isSandboxMode(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SANDBOX_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the Magento platform app ID.
     * Falls back to MAGENTO_APP_ID constant if not yet configured.
     */
    public function getAppId(?int $storeId = null): int
    {
        $configured = (int)$this->scopeConfig->getValue(
            self::XML_PATH_APP_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $configured > 0 ? $configured : self::MAGENTO_APP_ID;
    }

    public function getPublicKey(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_PUBLIC_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getPrivateKey(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_PRIVATE_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getAppInstanceId(?int $storeId = null): int
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_APP_INSTANCE_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function saveCredentials(string $publicKey, string $privateKey, int $appInstanceId): void
    {
        $this->configWriter->save(self::XML_PATH_PUBLIC_KEY, $publicKey);
        $this->configWriter->save(self::XML_PATH_PRIVATE_KEY, $privateKey);
        $this->configWriter->save(self::XML_PATH_APP_INSTANCE_ID, $appInstanceId);
    }

    public function saveAppId(int $appId): void
    {
        $this->configWriter->save(self::XML_PATH_APP_ID, $appId);
    }

    /**
     * Returns the XFVV security token used to authenticate IAPI requests.
     * Generates and persists one on first call if not yet set.
     */
    public function getXfvv(): string
    {
        $xfvv = trim((string)$this->scopeConfig->getValue(self::XML_PATH_XFVV));
        if ($xfvv === '') {
            $xfvv = hash('sha256', (string)time() . (string)random_int(1000000, 9999999));
            $this->configWriter->save(self::XML_PATH_XFVV, $xfvv);
        }
        return $xfvv;
    }

    public function getPaymentAction(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_PAYMENT_ACTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getNewOrderStatus(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_ORDER_STATUS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isDebugEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_DEBUG,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isConnected(?int $storeId = null): bool
    {
        return $this->getPublicKey($storeId) !== ''
            && $this->getPrivateKey($storeId) !== '';
    }

    public function isOperational(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->isConnected($storeId);
    }
}
