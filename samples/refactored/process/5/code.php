<?php
declare(strict_types=1);

namespace App\Console\Cache;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Cache\Cache;
use App\Cache\WarmTarget;
use App\Cache\WarmTargetRegistry;
use Psr\Log\LoggerInterface;

final class WarmCommand extends Command
{
    protected static $defaultName = 'cache:warm';

    public function __construct(
        private Cache $cache,
        private WarmTargetRegistry $registry,
        private LoggerInterface $log,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $target = $this->registry->get((string) $in->getArgument('target'));
        return $this->warm($target, $out);
    }

    private function warm(WarmTarget $t, OutputInterface $out): int
    {
        $t0 = microtime(true);
        $items = ($t->enumerate)();
        $out->writeln('Warming ' . count($items) . " {$t->label}…");
        $ok = 0;
        $err = 0;
        foreach ($items as $item) {
            try {
                $payload = ($t->render)($item);
                $this->cache->set(sprintf($t->keyFormat, $item), $payload, $t->ttl);
                $ok++;
            } catch (\Throwable $e) {
                $err++;
                $this->log->warning("{$t->label} warm failed", ['item' => $item, 'err' => $e->getMessage()]);
            }
            if ($ok % 10 === 0) {
                $out->writeln("…{$ok} warmed");
            }
        }
        $elapsed = microtime(true) - $t0;
        $this->log->info("{$t->label} warm complete", ['ok' => $ok, 'fail' => $err, 'elapsed' => $elapsed]);
        $out->writeln("<info>Done. OK {$ok}, fail {$err}, " . number_format($elapsed, 2) . 's</info>');
        return Command::SUCCESS;
    }
}
