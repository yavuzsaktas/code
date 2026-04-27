<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Model\Iapi;

use Eticsoft\PaythorClient\PaythorClient;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;

/**
 * Dispatches IAPI actions sent by the Paythor CDN admin app.
 *
 * Mirrors AdminSanalPosProIapiController / InternalApi from the PrestaShop
 * module. The CDN app POSTs: iapi_action, iapi_params (JSON), iapi_xfvv.
 */
class Handler
{
    private array $response = [
        'status'  => 'error',
        'message' => 'Internal error',
    ];

    public function __construct(
        private readonly PaymentConfig $paymentConfig,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Pool $cacheFrontendPool,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function run(RequestInterface $request): array
    {
        $action    = (string)$request->getPost('iapi_action', '');
        $xfvv      = (string)$request->getPost('iapi_xfvv', '');
        $rawParams = (string)$request->getPost('iapi_params', '{}');
        $params    = json_decode($rawParams, true) ?? [];

        if ($action === '') {
            return $this->error('Action not specified.');
        }

        if ($xfvv !== $this->paymentConfig->getXfvv()) {
            return $this->error('Security token mismatch.');
        }

        $method = 'action' . ucfirst($action);
        if (!method_exists($this, $method)) {
            return $this->error('Unknown action: ' . $action);
        }

        return $this->$method($params);
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    private function actionSaveApiKeys(array $params): array
    {
        $pub = trim((string)($params['iapi_publicKey'] ?? ''));
        $sec = trim((string)($params['iapi_secretKey'] ?? ''));

        if ($pub === '' || $sec === '') {
            return $this->error('Missing API keys.');
        }

        $this->configWriter->save(PaymentConfig::XML_PATH_PUBLIC_KEY, $pub);
        $this->configWriter->save(PaymentConfig::XML_PATH_PRIVATE_KEY, $sec);

        // Flush config cache so the immediately-following checkApiKeys reads fresh keys.
        $this->cacheTypeList->cleanType('config');
        foreach ($this->cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
        }

        return $this->success('API keys saved.');
    }

    private function actionCheckApiKeys(array $params): array
    {
        // Read directly from DB to avoid stale Magento config cache.
        // The CDN widget may call checkApiKeys before or independently of saveApiKeys,
        // so cached values could belong to a previous merchant session.
        $pub = $this->readConfigFromDb(PaymentConfig::XML_PATH_PUBLIC_KEY);
        $sec = $this->readConfigFromDb(PaymentConfig::XML_PATH_PRIVATE_KEY);

        if ($pub === '' || $sec === '') {
            return $this->error('API keys not configured.');
        }

        $client = $this->buildClient($pub, $sec);
        $raw    = $client->request('POST', '/check/accesstoken', [
            'accesstoken' => $params['iapi_accessToken'] ?? '',
        ]);

        return $client->decodeResponse($raw);
    }

    private function actionSetInstallmentOptions(array $params): array
    {
        $options = $params['iapi_installmentOptions'] ?? null;

        // Accept empty input as "clear all" — mirrors WooCommerce behavior where
        // saving an empty config simply resets the stored installments.
        if ($options === null) {
            $options = [];
        }

        $this->configWriter->save(
            'payment/paythor_sanalpospro/installments',
            json_encode($options)
        );

        return $this->success('Installment options updated successfully');
    }

    private function actionSetModuleSettings(array $params): array
    {
        $settings = $params['iapi_moduleSettings'] ?? [];
        if (empty($settings) || !is_array($settings)) {
            return $this->error('No settings provided.');
        }

        $allowed = ['order_status', 'currency_convert', 'showinstallmentstabs', 'paymentpagetheme'];
        foreach ($settings as $key => $value) {
            $normalized = strtolower((string)$key);
            if (in_array($normalized, $allowed, true)) {
                $this->configWriter->save('payment/paythor_sanalpospro/' . $normalized, $value);
            }
        }

        return $this->success('Settings updated.');
    }

    private function actionGetMerchantInfo(array $params): array
    {
        $pub = $this->paymentConfig->getPublicKey();
        $sec = $this->paymentConfig->getPrivateKey();

        if ($pub === '' || $sec === '') {
            return $this->error('API keys not configured.');
        }

        $client = $this->buildClient($pub, $sec);
        $raw    = $client->request('POST', '/merchant/info', []);

        return $client->decodeResponse($raw);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function buildClient(string $publicKey, string $privateKey): PaythorClient
    {
        $client = new PaythorClient([
            'base_url'    => PaymentConfig::API_BASE_URL,
            'public_key'  => $publicKey,
            'private_key' => $privateKey,
        ]);
        $client->setProgramId(PaymentConfig::PROGRAM_ID);
        $client->setAppId($this->paymentConfig->getAppId());

        return $client;
    }

    private function readConfigFromDb(string $path): string
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('core_config_data');
        $value = $connection->fetchOne(
            $connection->select()
                ->from($table, 'value')
                ->where('path = ?', $path)
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
        );
        return trim((string)($value ?? ''));
    }

    private function success(string $message, array $data = []): array
    {
        return [
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
            'details' => [],
            'meta'    => [
                'xfvv'  => substr($this->paymentConfig->getXfvv(), 0, 10),
                'nonce' => null,
            ],
        ];
    }

    private function error(string $message): array
    {
        return [
            'status'  => 'error',
            'message' => $message,
            'data'    => [],
            'details' => [],
            'meta'    => [
                'xfvv'  => substr($this->paymentConfig->getXfvv(), 0, 10),
                'nonce' => null,
            ],
        ];
    }
}
