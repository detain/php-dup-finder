<?php
declare(strict_types=1);

namespace App\Console\Backfill;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Tax\TaxRateService;
use Psr\Log\LoggerInterface;

final class BackfillOrderTaxRateCommand extends Command
{
    protected static $defaultName = 'backfill:order-tax-rate';

    public function __construct(
        private Connection $db,
        private TaxRateService $tax,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $chunk = 500;
        $done = 0;
        $failed = 0;
        $t0 = microtime(true);
        while (true) {
            $records = $this->db->fetchAll(
                'SELECT id, ship_zip FROM orders WHERE tax_rate IS NULL AND ship_zip IS NOT NULL LIMIT ?',
                [$chunk]
            );
            if ($records === []) {
                break;
            }
            foreach ($records as $rec) {
                try {
                    $rate = $this->tax->rateForZip($rec['ship_zip']);
                    $this->db->update('orders', ['tax_rate' => $rate], ['id' => $rec['id']]);
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->log->warning('order tax rate lookup failed', ['id' => $rec['id']]);
                }
            }
            $out->writeln("Done {$done}…");
        }
        $left = $this->db->fetchValue(
            'SELECT COUNT(*) FROM orders WHERE tax_rate IS NULL AND ship_zip IS NOT NULL'
        );
        $secs = microtime(true) - $t0;
        $this->log->info('order tax backfill complete', [
            'updated' => $done, 'errors' => $failed, 'remaining' => $left, 'elapsed' => $secs,
        ]);
        $out->writeln("<info>Done. Updated {$done}, errors {$failed}, remaining {$left}</info>");
        return Command::SUCCESS;
    }
}
