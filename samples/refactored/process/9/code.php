<?php
declare(strict_types=1);

namespace App\Console\Search;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Database\Connection;
use App\Search\SearchClient;
use App\Search\IndexProfile;
use App\Search\IndexProfileRegistry;
use Psr\Log\LoggerInterface;

final class ReindexCommand extends Command
{
    protected static $defaultName = 'search:reindex';

    public function __construct(
        private Connection $db,
        private SearchClient $search,
        private IndexProfileRegistry $registry,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('index', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $p = $this->registry->get((string) $in->getArgument('index'));
        $started = microtime(true);
        $expected = (int) $this->db->fetchValue($p->countSql);
        $out->writeln("Reindexing {$expected} {$p->name}");
        $this->search->recreate($p->name);
        $pushed = 0;
        $offset = 0;
        $batch = 500;
        while (true) {
            $rows = $this->db->fetchAll($p->fetchSql . ' LIMIT ? OFFSET ?', [$batch, $offset]);
            if ($rows === []) break;
            $docs = array_map($p->toDoc, $rows);
            $this->search->bulkIndex($p->name, $docs);
            $pushed += count($docs);
            $offset += $batch;
            $out->writeln("Pushed {$pushed}/{$expected}");
        }
        $actual = $this->search->count($p->name);
        if ($actual !== $expected) {
            $this->log->error("{$p->name} reindex mismatch", ['expected' => $expected, 'actual' => $actual]);
            $out->writeln("<error>Doc count mismatch: {$actual}/{$expected}</error>");
            return Command::FAILURE;
        }
        $this->log->info("{$p->name} reindex ok", ['count' => $actual, 'elapsed' => microtime(true) - $started]);
        $out->writeln("<info>Reindex OK: {$actual} docs</info>");
        return Command::SUCCESS;
    }
}
