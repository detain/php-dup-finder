<?php
declare(strict_types=1);

namespace App\Console\Cache;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Cache\Cache;
use App\Renderer\CategoryListingRenderer;
use App\Repo\CategoryRepo;
use Psr\Log\LoggerInterface;

final class WarmCategoryListingsCommand extends Command
{
    protected static $defaultName = 'cache:warm-categories';

    public function __construct(
        private Cache $cache,
        private CategoryListingRenderer $renderer,
        private CategoryRepo $repo,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $began = microtime(true);
        $slugs = $this->repo->activeSlugs();
        $out->writeln('Warming ' . count($slugs) . ' category listings…');
        $hits = 0;
        $misses = 0;
        foreach ($slugs as $slug) {
            try {
                $rendered = $this->renderer->render($slug);
                $k = "category:{$slug}";
                $this->cache->set($k, $rendered, 3600);
                $hits++;
            } catch (\Throwable $e) {
                $misses++;
                $this->log->warning('category warm failed', ['slug' => $slug, 'err' => $e->getMessage()]);
            }
            if ($hits % 10 === 0) {
                $out->writeln("…{$hits} warmed");
            }
        }
        $duration = microtime(true) - $began;
        $this->log->info('category warm complete', ['ok' => $hits, 'fail' => $misses, 'elapsed' => $duration]);
        $out->writeln("<info>Done. OK {$hits}, fail {$misses}, " . number_format($duration, 2) . 's</info>');
        return Command::SUCCESS;
    }
}
