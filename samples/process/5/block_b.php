<?php
declare(strict_types=1);

namespace App\Console\Cache;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Cache\Cache;
use App\Renderer\ProductPageRenderer;
use App\Repo\ProductRepo;
use Psr\Log\LoggerInterface;

final class WarmProductPagesCommand extends Command
{
    protected static $defaultName = 'cache:warm-products';

    public function __construct(
        private Cache $cache,
        private ProductPageRenderer $renderer,
        private ProductRepo $repo,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $t0 = microtime(true);
        $skus = $this->repo->activeSkus();
        $out->writeln('Warming ' . count($skus) . ' product pages…');
        $success = 0;
        $failure = 0;
        foreach ($skus as $sku) {
            try {
                $html = $this->renderer->render($sku);
                $cacheKey = "product:{$sku}";
                $this->cache->set($cacheKey, $html, 3600);
                $success++;
            } catch (\Throwable $e) {
                $failure++;
                $this->log->warning('product page warm failed', ['sku' => $sku, 'err' => $e->getMessage()]);
            }
            if ($success % 10 === 0) {
                $out->writeln("…{$success} warmed");
            }
        }
        $dt = microtime(true) - $t0;
        $this->log->info('product page warm complete', ['ok' => $success, 'fail' => $failure, 'elapsed' => $dt]);
        $out->writeln("<info>Done. OK {$success}, fail {$failure}, " . number_format($dt, 2) . 's</info>');
        return Command::SUCCESS;
    }
}
