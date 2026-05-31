<?php
declare(strict_types=1);

namespace App\Console\Search;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Search\SearchClient;
use Psr\Log\LoggerInterface;

final class ReindexProductsCommand extends Command
{
    protected static $defaultName = 'search:reindex-products';

    public function __construct(
        private Connection $db,
        private SearchClient $search,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $start = microtime(true);
        $expected = (int) $this->db->fetchValue('SELECT COUNT(*) FROM products WHERE active = 1');
        $out->writeln("Reindexing {$expected} products");
        $this->search->recreate('products');
        $pushed = 0;
        $offset = 0;
        $batch = 500;
        while (true) {
            $rows = $this->db->fetchAll(
                'SELECT id, sku, name, description, price FROM products WHERE active = 1 ORDER BY id LIMIT ? OFFSET ?',
                [$batch, $offset]
            );
            if ($rows === []) break;
            $docs = [];
            foreach ($rows as $r) {
                $docs[] = [
                    'id' => $r['id'],
                    'title' => $r['name'],
                    'body' => $r['description'],
                    'price' => (float) $r['price'],
                    'sku' => $r['sku'],
                ];
            }
            $this->search->bulkIndex('products', $docs);
            $pushed += count($docs);
            $offset += $batch;
            $out->writeln("Pushed {$pushed}/{$expected}");
        }
        $actual = $this->search->count('products');
        if ($actual !== $expected) {
            $this->log->error('product reindex mismatch', ['expected' => $expected, 'actual' => $actual]);
            $out->writeln("<error>Doc count mismatch: {$actual}/{$expected}</error>");
            return Command::FAILURE;
        }
        $this->log->info('product reindex ok', ['count' => $actual, 'elapsed' => microtime(true) - $start]);
        $out->writeln("<info>Reindex OK: {$actual} docs</info>");
        return Command::SUCCESS;
    }
}
