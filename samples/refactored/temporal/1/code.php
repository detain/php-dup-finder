<?php
declare(strict_types=1);

namespace Warehouse\Inventory\Stock;

use Predis\Client;
use Psr\Log\LoggerInterface;

final class RedisLockGuard
{
    public function __construct(private Client $redis, private LoggerInterface $log) {}

    /**
     * @template T
     * @param callable():T $work
     * @return T|null  null when the lock could not be acquired
     */
    public function withLock(string $key, float $waitSeconds, int $ttlSeconds, callable $work)
    {
        $token = bin2hex(random_bytes(8));
        $deadline = microtime(true) + $waitSeconds;
        while (microtime(true) < $deadline) {
            if ($this->redis->set($key, $token, 'NX', 'EX', $ttlSeconds) === 'OK') {
                try {
                    return $work();
                } finally {
                    $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
                    $this->redis->eval($script, 1, $key, $token);
                }
            }
            usleep(50_000);
        }
        $this->log->warning('lock.timeout', ['key' => $key]);
        return null;
    }
}

final class StockReservation
{
    public function __construct(private RedisLockGuard $guard, private StockRepository $repo) {}

    public function reserve(string $sku, int $qty, string $orderId): bool
    {
        return (bool) $this->guard->withLock("lock:stock:{$sku}", 5.0, 10, function () use ($sku, $qty, $orderId) {
            if ($this->repo->available($sku) < $qty) {
                return false;
            }
            $this->repo->decrement($sku, $qty);
            $this->repo->writeReservation($sku, $orderId, $qty);
            return true;
        });
    }
}
