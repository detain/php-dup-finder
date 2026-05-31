<?php

declare(strict_types=1);

namespace Acme\Common\Concurrency;

use Acme\Locking\LockManager;
use Psr\Log\LoggerInterface;

final class LockedRunner
{
    public function __construct(
        private readonly LockManager $locks,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     * @param callable(): ?T $work     Returns null when the resource isn't in the expected state.
     * @return T|null                  Returns null on contention or when work declined.
     */
    public function run(string $lockKey, int $ttlSeconds, string $label, callable $work): mixed
    {
        $lock = $this->locks->acquire($lockKey, $ttlSeconds);
        if ($lock === null) {
            $this->logger->info("{$label} lock contended", ['key' => $lockKey]);
            return null;
        }

        try {
            $result = $work();
            if ($result === null) {
                $this->logger->debug("{$label} not eligible", ['key' => $lockKey]);
            } else {
                $this->logger->info("{$label} completed", ['key' => $lockKey]);
            }
            return $result;
        } finally {
            $this->locks->release($lock);
        }
    }
}

// Each caller now passes a small closure that does only the
// state-check + mutate steps; the lock skeleton lives in one place.
