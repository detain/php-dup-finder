<?php
declare(strict_types=1);

namespace App\Console\Cache;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Cache\Cache;
use App\Renderer\HomepageRenderer;
use App\Repo\HomepageRepo;
use Psr\Log\LoggerInterface;

final class WarmHomepagesCommand extends Command
{
    protected static $defaultName = 'cache:warm-homepages';

    public function __construct(
        private Cache $cache,
        private HomepageRenderer $renderer,
        private HomepageRepo $repo,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $start = microtime(true);
        $ids = $this->repo->activeLocaleIds();
        $out->writeln('Warming ' . count($ids) . ' homepages…');
        $okay = 0;
        $fail = 0;
        foreach ($ids as $localeId) {
            try {
                $html = $this->renderer->render($localeId);
                $key = "home:{$localeId}";
                $this->cache->set($key, $html, 3600);
                $okay++;
            } catch (\Throwable $e) {
                $fail++;
                $this->log->warning('homepage warm failed', ['locale' => $localeId, 'err' => $e->getMessage()]);
            }
            if ($okay % 10 === 0) {
                $out->writeln("…{$okay} warmed");
            }
        }
        $elapsed = microtime(true) - $start;
        $this->log->info('homepage warm complete', ['ok' => $okay, 'fail' => $fail, 'elapsed' => $elapsed]);
        $out->writeln("<info>Done. OK {$okay}, fail {$fail}, " . number_format($elapsed, 2) . 's</info>');
        return Command::SUCCESS;
    }
}
