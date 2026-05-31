<?php
declare(strict_types=1);

namespace App\Console\Reconcile;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\External\PaymentGatewayClient;
use App\Database\Connection;
use App\Mail\Mailer;

final class ReconcilePaymentsCommand extends Command
{
    protected static $defaultName = 'reconcile:payments';

    public function __construct(
        private PaymentGatewayClient $gw,
        private Connection $db,
        private Mailer $mailer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $day = date('Y-m-d', strtotime('-1 day'));
        $out->writeln("Reconciling gateway settlements for {$day}");
        $settled = $this->gw->settlements($day);
        if ($settled === []) {
            $out->writeln('<comment>Gateway returned no rows</comment>');
            return Command::SUCCESS;
        }
        $localRows = $this->db->fetchAll(
            'SELECT id, gateway_ref, amount FROM payments WHERE captured_on = ?',
            [$day]
        );
        $byRef = [];
        foreach ($localRows as $r) {
            $byRef[$r['gateway_ref']] = $r;
        }
        $report = ['only_remote' => [], 'amount_diff' => [], 'only_local' => []];
        foreach ($settled as $s) {
            if (!isset($byRef[$s['ref']])) {
                $report['only_remote'][] = $s;
            } elseif ((float) $byRef[$s['ref']]['amount'] !== (float) $s['amount']) {
                $report['amount_diff'][] = ['remote' => $s, 'local' => $byRef[$s['ref']]];
            }
            unset($byRef[$s['ref']]);
        }
        $report['only_local'] = array_values($byRef);
        $file = "/var/reports/payment_recon_{$day}.json";
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
        $summary = sprintf(
            "Payment recon %s\nOnly remote: %d\nAmount diffs: %d\nOnly local: %d",
            $day,
            count($report['only_remote']),
            count($report['amount_diff']),
            count($report['only_local'])
        );
        $this->mailer->sendWithAttachment('finance@example.com', "Payment Recon {$day}", $summary, $file);
        $out->writeln($summary);
        return Command::SUCCESS;
    }
}
