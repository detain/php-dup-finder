<?php
declare(strict_types=1);

namespace App\Console\Backfill;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Database\Connection;
use App\Backfill\BackfillProfile;
use App\Backfill\BackfillRegistry;
use Psr\Log\LoggerInterface;

final class BackfillCommand extends Command
{
    protected static $defaultName = 'backfill:run';

    public function __construct(
        private Connection $db,
        private BackfillRegistry $registry,
        private LoggerInterface $log,
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
        return $this->runBackfill($p, $out);
    }

    private function runBackfill(BackfillProfile $p, OutputInterface $out): int
    {
        $batch = 500;
        $updated = 0;
        $errors = 0;
        $t0 = microtime(true);
        while (true) {
            $rows = $this->db->fetchAll($p->selectSql . ' LIMIT ?', [$batch]);
            if ($rows === []) break;
            foreach ($rows as $row) {
                try {
                    $value = ($p->compute)($row);
                    $this->db->update($p->table, [$p->column => $value], ['id' => $row['id']]);
                    $updated++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->log->warning("{$p->name} backfill row failed", ['id' => $row['id']]);
                }
            }
            $out->writeln("Processed {$updated}…");
        }
        $remaining = $this->db->fetchValue($p->countSql);
        $elapsed = microtime(true) - $t0;
        $this->log->info("{$p->name} backfill complete", compact('updated', 'errors', 'remaining', 'elapsed'));
        $out->writeln("<info>Done. Updated {$updated}, errors {$errors}, remaining {$remaining}</info>");
        return Command::SUCCESS;
    }
}
