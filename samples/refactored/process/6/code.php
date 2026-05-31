<?php
declare(strict_types=1);

namespace App\Console\Cleanup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Database\Connection;
use App\Archive\ColdStorage;
use App\Cleanup\CleanupProfile;
use App\Cleanup\CleanupRegistry;

final class CleanupCommand extends Command
{
    protected static $defaultName = 'cleanup:run';

    public function __construct(
        private Connection $db,
        private ColdStorage $cold,
        private CleanupRegistry $registry,
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
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$p->ageDays} days"));
        $out->writeln("Cleaning {$p->table} older than {$cutoff}");
        $rows = $this->db->fetchAll(
            "SELECT * FROM {$p->table} WHERE {$p->ageColumn} < ?" . ($p->extraWhere ? " AND {$p->extraWhere}" : ''),
            [$cutoff]
        );
        if ($rows === []) {
            $out->writeln('Nothing to clean');
            return Command::SUCCESS;
        }
        $archived = 0;
        foreach ($rows as $r) {
            $this->cold->put($p->table, $r['id'], $r);
            $archived++;
        }
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $deleted = $this->db->execute(
            "DELETE FROM {$p->table} WHERE id IN ({$placeholders})",
            $ids
        );
        $this->db->insert('audit_log', [
            'event' => "cleanup_{$p->table}",
            'archived' => $archived,
            'deleted' => $deleted,
            'run_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Archived {$archived}, deleted {$deleted}</info>");
        return Command::SUCCESS;
    }
}
