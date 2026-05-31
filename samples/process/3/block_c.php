<?php
declare(strict_types=1);

namespace App\Console\Reconcile;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\External\CarrierClient;
use App\Database\Connection;
use App\Mail\Mailer;

final class ReconcileShipmentsCommand extends Command
{
    protected static $defaultName = 'reconcile:shipments';

    public function __construct(
        private CarrierClient $carrier,
        private Connection $db,
        private Mailer $mailer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $when = date('Y-m-d', strtotime('-1 day'));
        $out->writeln("Reconciling carrier manifests for {$when}");
        $manifest = $this->carrier->dailyManifest($when);
        if ($manifest === []) {
            $out->writeln('<comment>Empty manifest</comment>');
            return Command::SUCCESS;
        }
        $localShipments = $this->db->fetchAll(
            'SELECT id, tracking, weight FROM shipments WHERE shipped_on = ?',
            [$when]
        );
        $idx = [];
        foreach ($localShipments as $row) {
            $idx[$row['tracking']] = $row;
        }
        $out_diff = ['missing' => [], 'weight_mismatch' => [], 'orphans' => []];
        foreach ($manifest as $m) {
            if (!isset($idx[$m['tracking']])) {
                $out_diff['missing'][] = $m;
            } elseif ((float) $idx[$m['tracking']]['weight'] !== (float) $m['weight']) {
                $out_diff['weight_mismatch'][] = ['remote' => $m, 'local' => $idx[$m['tracking']]];
            }
            unset($idx[$m['tracking']]);
        }
        $out_diff['orphans'] = array_values($idx);
        $reportPath = "/var/reports/ship_recon_{$when}.json";
        file_put_contents($reportPath, json_encode($out_diff, JSON_PRETTY_PRINT));
        $text = sprintf(
            "Shipment recon %s\nMissing: %d\nWeight mismatch: %d\nOrphans: %d",
            $when,
            count($out_diff['missing']),
            count($out_diff['weight_mismatch']),
            count($out_diff['orphans'])
        );
        $this->mailer->sendWithAttachment('logistics@example.com', "Ship Recon {$when}", $text, $reportPath);
        $out->writeln($text);
        return Command::SUCCESS;
    }
}
