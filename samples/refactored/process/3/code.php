<?php
declare(strict_types=1);

namespace App\Console\Reconcile;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Database\Connection;
use App\Mail\Mailer;
use App\Reconcile\ReconcileProfile;
use App\Reconcile\ReconcileProfileRegistry;

final class ReconcileCommand extends Command
{
    protected static $defaultName = 'reconcile:run';

    public function __construct(
        private Connection $db,
        private Mailer $mailer,
        private ReconcileProfileRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('profile', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $p = $this->registry->get((string) $in->getArgument('profile'));
        $day = date('Y-m-d', strtotime('-1 day'));
        $remote = ($p->fetchRemote)($day);
        if ($remote === []) {
            $out->writeln('<comment>No remote rows</comment>');
            return Command::SUCCESS;
        }
        $localRows = $this->db->fetchAll($p->localSql, [$day]);
        $byKey = [];
        foreach ($localRows as $r) {
            $byKey[$r[$p->localKey]] = $r;
        }
        $diff = ['missing_local' => [], 'mismatch' => [], 'extra_local' => []];
        foreach ($remote as $r) {
            $k = $r[$p->remoteKey];
            if (!isset($byKey[$k])) {
                $diff['missing_local'][] = $r;
            } elseif ((float) $byKey[$k][$p->compareField] !== (float) $r[$p->compareField]) {
                $diff['mismatch'][] = ['remote' => $r, 'local' => $byKey[$k]];
            }
            unset($byKey[$k]);
        }
        $diff['extra_local'] = array_values($byKey);
        $path = "/var/reports/{$p->name}_recon_{$day}.json";
        file_put_contents($path, json_encode($diff, JSON_PRETTY_PRINT));
        $body = sprintf("%s recon %s\nMissing local: %d\nMismatch: %d\nExtra local: %d",
            $p->label, $day, count($diff['missing_local']), count($diff['mismatch']), count($diff['extra_local']));
        $this->mailer->sendWithAttachment($p->recipient, "{$p->label} Recon {$day}", $body, $path);
        $out->writeln($body);
        return Command::SUCCESS;
    }
}
