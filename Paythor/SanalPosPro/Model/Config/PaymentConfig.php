<?php
/**
 * File: app/code/Paythor/SanalPosPro/Model/Config/PaymentConfig.php
 *
 * Strongly-typed configuration accessor for the Paythor SanalPos Pro
 * payment method. All admin-configured values are read through this
 * class to keep ScopeConfigInterface usage out of business logic.
 */
declare(strict_types=1);

namespace Paythor\SanalPosPro\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class PaymentConfig
{
    public const METHOD_CODE = 'paythor_sanalpospro';

    private const XML_PATH_PREFIX = 'payment/' . self::METHOD_CODE . '/';

    public const XML_PATH_ACTIVE              = self::XML_PATH_PREFIX . 'active';
    public const XML_PATH_TITLE               = self::XML_PATH_PREFIX . 'title';
    public const XML_PATH_SANDBOX_MODE        = self::XML_PATH_PREFIX . 'sandbox_mode';
    public const XML_PATH_API_KEY             = self::XML_PATH_PREFIX . 'api_key';
    public const XML_PATH_API_SECRET          = self::XML_PATH_PREFIX . 'api_secret';
    public const XML_PATH_WEBHOOK_SECRET      = self::XML_PATH_PREFIX . 'webhook_secret';
    public const XML_PATH_SANDBOX_ENDPOINT    = self::XML_PATH_PREFIX . 'sandbox_endpoint';
    public const XML_PATH_PRODUCTION_ENDPOINT = self::XML_PATH_PREFIX . 'production_endpoint';
    public const XML_PATH_PAYMENT_ACTION      = self::XML_PATH_PREFIX . 'payment_action';
    public const XML_PATH_ORDER_STATUS        = self::XML_PATH_PREFIX . 'order_status';
    public const XML_PATH_DEBUG               = self::XML_PATH_PREFIX . 'debug';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface   $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
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

    public function getApiKey(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Returns the decrypted API secret.
     */
    public function getApiSecret(?int $storeId = null): string
    {
        $encrypted = (string)$this->scopeConfig->getValue(
            self::XML_PATH_API_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $encrypted === '' ? '' : (string)$this->encryptor->decrypt($encrypted);
    }

    /**
     * Returns the decrypted webhook HMAC secret.
     */
    public function getWebhookSecret(?int $storeId = null): string
    {
        $encrypted = (string)$this->scopeConfig->getValue(
            self::XML_PATH_WEBHOOK_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $encrypted === '' ? '' : (string)$this->encryptor->decrypt($encrypted);
    }

    /**
     * Returns the active API endpoint based on sandbox flag.
     */
    public function getApiEndpoint(?int $storeId = null): string
    {
        $path = $this->isSandboxMode($storeId)
            ? self::XML_PATH_SANDBOX_ENDPOINT
            : self::XML_PATH_PRODUCTION_ENDPOINT;

        return rtrim((string)$this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ), '/');
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

    /**
     * Verifies the module is operationally ready (active + credentials present).
     */
    public function isOperational(?int $storeId = null): bool
    {
        return $this->isActive($storeId)
            && $this->getApiKey($storeId) !== ''
            && $this->getApiSecret($storeId) !== '';
    }
}
