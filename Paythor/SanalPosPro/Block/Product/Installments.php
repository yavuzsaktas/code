<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Block\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;

/**
 * Renders the SanalPosPro installment options table on the product page.
 *
 * Mirrors the WooCommerce reference (templates/installment_theme/modern.php):
 *   - Card families with no active installment are skipped entirely.
 *   - For each family a 12-row table is built; missing months show '-'.
 *   - Month 1 with buyer_fee_percent == 0 -> total = price (cash).
 *   - Otherwise: total = price * 100 / (100 - buyer_fee_percent), monthly = total / months.
 */
class Installments extends Template
{
    /** Card families rendered (matches WC modern.php order). */
    private const CARD_FAMILIES = [
        'world', 'axess', 'bonus', 'cardfinans', 'maximum',
        'paraf', 'saglamcard', 'advantage', 'combo', 'miles-smiles',
    ];

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly PaymentConfig $paymentConfig,
        private readonly PriceCurrencyInterface $priceFormatter,
        private readonly ResourceConnection $resource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProduct(): ?ProductInterface
    {
        $product = $this->registry->registry('current_product');
        return $product instanceof ProductInterface ? $product : null;
    }

    public function isEnabled(): bool
    {
        if (!$this->paymentConfig->isConnected()) {
            return false;
        }
        $flag = strtolower($this->readDb('showinstallmentstabs'));
        return $flag === 'yes' || $flag === '1';
    }

    /**
     * Active installment-table theme. Mirrors the WC plugin setting
     * (admin: "Installments Tab Theme"), persisted as `paymentpagetheme`.
     *
     * @return string 'classic' or 'modern'
     */
    public function getTheme(): string
    {
        $theme = strtolower($this->readDb('paymentpagetheme'));
        return $theme === 'classic' ? 'classic' : 'modern';
    }

    /**
     * Build a fixed 12-row table for one card family (classic theme).
     *
     * Inactive months render with '-' placeholders so the layout matches
     * WC's classic.php verbatim. Active months use the same total/monthly
     * formula as {@see getFamilyRows()}.
     *
     * @return array<int, array{months:int, monthly:string, total:string, active:bool}>
     */
    public function getFamilyRowsAll(string $family): array
    {
        $price   = $this->getProductPrice();
        $byMonth = [];
        foreach (($this->getInstallmentsRaw()[$family] ?? []) as $installment) {
            if (($installment['gateway'] ?? 'off') === 'off') {
                continue;
            }
            $months = (int)($installment['months'] ?? 0);
            if ($months >= 1 && $months <= 12) {
                $byMonth[$months] = (float)($installment['buyer_fee_percent'] ?? 0);
            }
        }

        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            if (!array_key_exists($i, $byMonth)) {
                $rows[] = ['months' => $i, 'monthly' => '-', 'total' => '-', 'active' => false];
                continue;
            }
            $buyerFee = $byMonth[$i];
            if ($i === 1 && $buyerFee == 0.0) {
                $total = $price;
            } else {
                $denominator = 100 - $buyerFee;
                $total = $denominator > 0 ? ($price * 100) / $denominator : $price;
            }
            $monthly = $total / $i;
            $rows[] = [
                'months'  => $i,
                'monthly' => $this->formatPrice($monthly),
                'total'   => $this->formatPrice($total),
                'active'  => true,
            ];
        }
        return $rows;
    }

    /**
     * Allow layout XML to declare an alternate template per theme; we swap into
     * it just before rendering when the admin's "Installments Tab Theme" picks
     * a non-default variant. Layout passes the path via a `<theme>_template`
     * argument (e.g. `classic_template`, `modern_template`).
     */
    protected function _beforeToHtml()
    {
        $variantTemplate = (string)$this->getData($this->getTheme() . '_template');
        if ($variantTemplate !== '') {
            $this->setTemplate($variantTemplate);
        }
        return parent::_beforeToHtml();
    }

    /**
     * @return string[] Card-family keys that have at least one active installment.
     *                  Mirrors WooCommerce: a family is listed only when it has its
     *                  own non-off override (no implicit fallback to default).
     *                  When no family has overrides we expose a single 'default' tab.
     */
    public function getActiveCardFamilies(): array
    {
        $data = $this->getInstallmentsRaw();
        $active = [];
        foreach (self::CARD_FAMILIES as $family) {
            if ($this->familyHasInstallment($data[$family] ?? [])) {
                $active[] = $family;
            }
        }
        if (empty($active) && $this->familyHasInstallment($data['default'] ?? [])) {
            $active[] = 'default';
        }
        return $active;
    }

    /**
     * Build the rendered rows for one card family.
     *
     * Only months whose own row has gateway != 'off' are returned, in ascending
     * month order (no '-' placeholders for missing months — matches WC).
     *
     * @return array<int, array{months:int, monthly:string, total:string}>
     */
    public function getFamilyRows(string $family): array
    {
        $price = $this->getProductPrice();
        $rows  = $this->getInstallmentsRaw()[$family] ?? [];

        $byMonth = [];
        foreach ($rows as $installment) {
            if (($installment['gateway'] ?? 'off') === 'off') {
                continue;
            }
            $months = (int)($installment['months'] ?? 0);
            if ($months < 1 || $months > 12) {
                continue;
            }

            $buyerFee = (float)($installment['buyer_fee_percent'] ?? 0);

            if ($months === 1 && $buyerFee == 0.0) {
                $total = $price;
            } else {
                $denominator = 100 - $buyerFee;
                $total = $denominator > 0 ? ($price * 100) / $denominator : $price;
            }
            $monthly = $total / $months;

            $byMonth[$months] = [
                'months'  => $months,
                'monthly' => $this->formatPrice($monthly),
                'total'   => $this->formatPrice($total),
            ];
        }
        ksort($byMonth);
        return array_values($byMonth);
    }

    public function getFamilyLabel(string $family): string
    {
        if ($family === 'default') {
            return (string)__('Tüm Kartlar');
        }
        return ucwords(str_replace('-', ' ', $family));
    }

    public function getCardImageUrl(string $family): string
    {
        $file = preg_replace('/[^a-z0-9\-]/', '', strtolower($family)) ?: 'default';
        $appDir = $this->_filesystem->getDirectoryRead(DirectoryList::APP);
        $relative = 'code/Paythor/SanalPosPro/view/frontend/web/images/cards/' . $file . '.png';
        if (!$appDir->isFile($relative)) {
            $file = 'default';
        }
        return $this->getViewFileUrl('Paythor_SanalPosPro::images/cards/' . $file . '.png');
    }

    public function formatPrice(float $value): string
    {
        return (string)$this->priceFormatter->format($value, false);
    }

    private function getProductPrice(): float
    {
        $product = $this->getProduct();
        return $product ? (float)$product->getFinalPrice() : 0.0;
    }

    private function getInstallmentsRaw(): array
    {
        $raw = $this->readDb('installments');
        $data = json_decode($raw ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    private function familyHasInstallment(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['gateway'] ?? 'off') !== 'off') {
                return true;
            }
        }
        return false;
    }

    /**
     * Bypass config cache so the table reflects the latest IAPI save.
     */
    private function readDb(string $key): string
    {
        $connection = $this->resource->getConnection();
        $value = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), 'value')
                ->where('path = ?', 'payment/paythor_sanalpospro/' . strtolower($key))
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
        );
        return trim((string)($value ?? ''));
    }
}
