<?php
declare(strict_types=1);

namespace App\Console\Search;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Search\SearchClient;
use Psr\Log\LoggerInterface;

final class ReindexTicketsCommand extends Command
{
    protected static $defaultName = 'search:reindex-tickets';

    public function __construct(
        private Connection $db,
        private SearchClient $search,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $t0 = microtime(true);
        $expected = (int) $this->db->fetchValue('SELECT COUNT(*) FROM support_tickets WHERE deleted_at IS NULL');
        $out->writeln("Reindexing {$expected} tickets");
        $this->search->recreate('tickets');
        $done = 0;
        $page = 0;
        $size = 500;
        while (true) {
            $rows = $this->db->fetchAll(
                'SELECT id, subject, body, status, customer_email FROM support_tickets WHERE deleted_at IS NULL ORDER BY id LIMIT ? OFFSET ?',
                [$size, $page]
            );
            if ($rows === []) break;
            $docs = [];
            foreach ($rows as $r) {
                $docs[] = [
                    'id' => $r['id'],
                    'title' => $r['subject'],
                    'body' => $r['body'],
                    'status' => $r['status'],
                    'customer' => $r['customer_email'],
                ];
            }
            $this->search->bulkIndex('tickets', $docs);
            $done += count($docs);
            $page += $size;
            $out->writeln("Pushed {$done}/{$expected}");
        }
        $actual = $this->search->count('tickets');
        if ($actual !== $expected) {
            $this->log->error('ticket reindex mismatch', ['expected' => $expected, 'actual' => $actual]);
            $out->writeln("<error>Doc count mismatch: {$actual}/{$expected}</error>");
            return Command::FAILURE;
        }
        $this->log->info('ticket reindex ok', ['count' => $actual, 'elapsed' => microtime(true) - $t0]);
        $out->writeln("<info>Reindex OK: {$actual} docs</info>");
        return Command::SUCCESS;
    }
}
