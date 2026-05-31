<?php
declare(strict_types=1);

namespace App\Console\Cleanup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Archive\ColdStorage;

final class CleanupSoftDeletedCommentsCommand extends Command
{
    protected static $defaultName = 'cleanup:comments';

    public function __construct(
        private Connection $db,
        private ColdStorage $cold,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $before = date('Y-m-d H:i:s', strtotime('-90 days'));
        $out->writeln("Cleaning comments soft-deleted before {$before}");
        $rows = $this->db->fetchAll(
            'SELECT * FROM comments WHERE deleted_at IS NOT NULL AND deleted_at < ?',
            [$before]
        );
        if ($rows === []) {
            $out->writeln('Nothing to clean');
            return Command::SUCCESS;
        }
        $movedToCold = 0;
        foreach ($rows as $r) {
            $this->cold->put('comments', $r['id'], $r);
            $movedToCold++;
        }
        $idList = array_column($rows, 'id');
        $place = implode(',', array_fill(0, count($idList), '?'));
        $removed = $this->db->execute(
            "DELETE FROM comments WHERE id IN ({$place})",
            $idList
        );
        $this->db->insert('audit_log', [
            'event' => 'cleanup_comments',
            'archived' => $movedToCold,
            'deleted' => $removed,
            'run_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Archived {$movedToCold}, deleted {$removed}</info>");
        return Command::SUCCESS;
    }
}
