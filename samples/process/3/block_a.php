<?php
declare(strict_types=1);

namespace App\Console\Reconcile;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\External\BankClient;
use App\Database\Connection;
use App\Mail\Mailer;

final class ReconcileBankCommand extends Command
{
    protected static $defaultName = 'reconcile:bank';

    public function __construct(
        private BankClient $bank,
        private Connection $db,
        private Mailer $mailer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $out->writeln("Reconciling bank transactions for {$date}");
        $remote = $this->bank->fetchTransactions($date);
        if ($remote === []) {
            $out->writeln('<comment>No remote rows</comment>');
            return Command::SUCCESS;
        }
        $local = $this->db->fetchAll(
            'SELECT id, ext_id, amount FROM bank_txns WHERE txn_date = ?',
            [$date]
        );
        $localByExt = [];
        foreach ($local as $row) {
            $localByExt[$row['ext_id']] = $row;
        }
        $diff = ['missing_local' => [], 'mismatch' => [], 'extra_local' => []];
        foreach ($remote as $r) {
            if (!isset($localByExt[$r['id']])) {
                $diff['missing_local'][] = $r;
            } elseif ((float) $localByExt[$r['id']]['amount'] !== (float) $r['amount']) {
                $diff['mismatch'][] = ['remote' => $r, 'local' => $localByExt[$r['id']]];
            }
            unset($localByExt[$r['id']]);
        }
        $diff['extra_local'] = array_values($localByExt);
        $path = "/var/reports/bank_recon_{$date}.json";
        file_put_contents($path, json_encode($diff, JSON_PRETTY_PRINT));
        $body = sprintf(
            "Bank reconciliation %s\nMissing local: %d\nMismatched: %d\nExtra local: %d",
            $date,
            count($diff['missing_local']),
            count($diff['mismatch']),
            count($diff['extra_local'])
        );
        $this->mailer->sendWithAttachment('finance@example.com', "Bank Recon {$date}", $body, $path);
        $out->writeln($body);
        return Command::SUCCESS;
    }
}
