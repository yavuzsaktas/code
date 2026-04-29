<?php
declare(strict_types=1);

namespace Paythor\SanalPosPro\Console\Command;

use Magento\Framework\App\State;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Paythor\SanalPosPro\Model\Api\PaythorAdapter;
use Paythor\SanalPosPro\Model\Config\PaymentConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retroactively sends payment/capture to Paythor for all Processing orders
 * that still show "Initiated" because the capture call was never made.
 *
 * Usage:
 *   php bin/magento paythor:capture-initiated
 *   php bin/magento paythor:capture-initiated --dry-run
 *   php bin/magento paythor:capture-initiated --order-id=000000030
 */
class CaptureInitiatedPayments extends Command
{
    private const OPT_DRY_RUN  = 'dry-run';
    private const OPT_ORDER_ID = 'order-id';

    public function __construct(
        private readonly PaythorAdapter $paythorAdapter,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('paythor:capture-initiated')
            ->setDescription('Send payment/capture to Paythor for Processing orders still showing Initiated.')
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Show what would be captured without actually calling the API.')
            ->addOption(self::OPT_ORDER_ID, null, InputOption::VALUE_OPTIONAL, 'Process a single order by increment ID (e.g. 000000030).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area code already set — fine.
        }

        $isDryRun  = (bool)$input->getOption(self::OPT_DRY_RUN);
        $orderId   = trim((string)($input->getOption(self::OPT_ORDER_ID) ?? ''));

        if ($isDryRun) {
            $output->writeln('<comment>DRY RUN — no API calls will be made.</comment>');
        }

        $collection = $this->buildOrderCollection($orderId);
        $total      = $collection->getSize();

        if ($total === 0) {
            $output->writeln('<info>No eligible orders found.</info>');
            return Command::SUCCESS;
        }

        $output->writeln("<info>Found {$total} eligible order(s).</info>");

        $succeeded = 0;
        $failed    = 0;
        $skipped   = 0;

        /** @var Order $order */
        foreach ($collection as $order) {
            $payment      = $order->getPayment();
            $processToken = (string)($payment->getAdditionalInformation('paythor_process_token') ?? '');
            $amount       = (float)$order->getGrandTotal();
            $currency     = (string)$order->getOrderCurrencyCode();
            $storeId      = (int)$order->getStoreId();

            if ($processToken === '') {
                $output->writeln(
                    "<comment>  SKIP  #{$order->getIncrementId()} — no process token stored.</comment>"
                );
                $skipped++;
                continue;
            }

            if ($isDryRun) {
                $output->writeln(
                    "  DRY   #{$order->getIncrementId()} | token=" . substr($processToken, 0, 16) . "... | {$amount} {$currency}"
                );
                $succeeded++;
                continue;
            }

            $ok = $this->paythorAdapter->capturePayment($processToken, $amount, $currency, $storeId);

            if ($ok) {
                $output->writeln("<info>  OK    #{$order->getIncrementId()} — capture acknowledged.</info>");
                $succeeded++;
            } else {
                $output->writeln("<error>  FAIL  #{$order->getIncrementId()} — capture returned error (see paythor_sanalpospro.log).</error>");
                $failed++;
            }
        }

        $output->writeln('');
        $output->writeln("Done. Succeeded: {$succeeded} | Failed: {$failed} | Skipped (no token): {$skipped}");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function buildOrderCollection(string $orderId): \Magento\Sales\Model\ResourceModel\Order\Collection
    {
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('state', Order::STATE_PROCESSING)
            ->addFieldToFilter('status', ['neq' => 'closed']);

        // Join to payment table to filter by Paythor payment method.
        $collection->getSelect()->joinInner(
            ['sop' => $collection->getResource()->getTable('sales_order_payment')],
            'sop.parent_id = main_table.entity_id AND sop.method = \'' . PaymentConfig::METHOD_CODE . '\'',
            []
        );

        if ($orderId !== '') {
            $collection->addFieldToFilter('increment_id', $orderId);
        }

        $collection->setOrder('entity_id', 'ASC');

        return $collection;
    }
}
