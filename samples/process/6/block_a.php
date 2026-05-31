<?php
declare(strict_types=1);

namespace App\Console\Cleanup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Archive\ColdStorage;

final class CleanupExpiredSessionsCommand extends Command
{
    protected static $defaultName = 'cleanup:sessions';

    public function __construct(
        private Connection $db,
        private ColdStorage $cold,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $out->writeln("Cleaning sessions older than {$cutoff}");
        $stale = $this->db->fetchAll(
            'SELECT * FROM sessions WHERE last_seen_at < ?',
            [$cutoff]
        );
        if ($stale === []) {
            $out->writeln('Nothing to clean');
            return Command::SUCCESS;
        }
        $archived = 0;
        foreach ($stale as $row) {
            $this->cold->put('sessions', $row['id'], $row);
            $archived++;
        }
        $ids = array_column($stale, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $deleted = $this->db->execute(
            "DELETE FROM sessions WHERE id IN ({$placeholders})",
            $ids
        );
        $this->db->insert('audit_log', [
            'event' => 'cleanup_sessions',
            'archived' => $archived,
            'deleted' => $deleted,
            'run_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Archived {$archived}, deleted {$deleted}</info>");
        return Command::SUCCESS;
    }
}
