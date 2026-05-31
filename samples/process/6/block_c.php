<?php
declare(strict_types=1);

namespace App\Console\Cleanup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Archive\ColdStorage;

final class CleanupAuditLogsCommand extends Command
{
    protected static $defaultName = 'cleanup:audit-logs';

    public function __construct(
        private Connection $db,
        private ColdStorage $cold,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $threshold = date('Y-m-d H:i:s', strtotime('-365 days'));
        $out->writeln("Cleaning audit logs older than {$threshold}");
        $old = $this->db->fetchAll(
            'SELECT * FROM activity_log WHERE created_at < ?',
            [$threshold]
        );
        if ($old === []) {
            $out->writeln('Nothing to clean');
            return Command::SUCCESS;
        }
        $cold_count = 0;
        foreach ($old as $entry) {
            $this->cold->put('activity_log', $entry['id'], $entry);
            $cold_count++;
        }
        $ids = array_column($old, 'id');
        $bind = implode(',', array_fill(0, count($ids), '?'));
        $purged = $this->db->execute(
            "DELETE FROM activity_log WHERE id IN ({$bind})",
            $ids
        );
        $this->db->insert('audit_log', [
            'event' => 'cleanup_activity_log',
            'archived' => $cold_count,
            'deleted' => $purged,
            'run_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Archived {$cold_count}, deleted {$purged}</info>");
        return Command::SUCCESS;
    }
}
