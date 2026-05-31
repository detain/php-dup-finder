<?php
declare(strict_types=1);

namespace App\Console\Search;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Search\SearchClient;
use Psr\Log\LoggerInterface;

final class ReindexArticlesCommand extends Command
{
    protected static $defaultName = 'search:reindex-articles';

    public function __construct(
        private Connection $db,
        private SearchClient $search,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $started = microtime(true);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM articles WHERE published = 1');
        $out->writeln("Reindexing {$total} articles");
        $this->search->recreate('articles');
        $sent = 0;
        $skip = 0;
        $size = 500;
        while (true) {
            $items = $this->db->fetchAll(
                'SELECT id, slug, title, body_html, author FROM articles WHERE published = 1 ORDER BY id LIMIT ? OFFSET ?',
                [$size, $skip]
            );
            if ($items === []) break;
            $docs = [];
            foreach ($items as $i) {
                $docs[] = [
                    'id' => $i['id'],
                    'title' => $i['title'],
                    'body' => strip_tags($i['body_html']),
                    'author' => $i['author'],
                    'slug' => $i['slug'],
                ];
            }
            $this->search->bulkIndex('articles', $docs);
            $sent += count($docs);
            $skip += $size;
            $out->writeln("Pushed {$sent}/{$total}");
        }
        $actual = $this->search->count('articles');
        if ($actual !== $total) {
            $this->log->error('article reindex mismatch', ['expected' => $total, 'actual' => $actual]);
            $out->writeln("<error>Doc count mismatch: {$actual}/{$total}</error>");
            return Command::FAILURE;
        }
        $this->log->info('article reindex ok', ['count' => $actual, 'elapsed' => microtime(true) - $started]);
        $out->writeln("<info>Reindex OK: {$actual} docs</info>");
        return Command::SUCCESS;
    }
}
