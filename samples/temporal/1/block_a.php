<?php
declare(strict_types=1);

namespace Warehouse\Inventory\Stock;

use Predis\Client;
use Psr\Log\LoggerInterface;

final class StockReservation
{
    public function __construct(
        private Client $redis,
        private StockRepository $repo,
        private LoggerInterface $log,
    ) {}

    public function reserve(string $sku, int $qty, string $orderId): bool
    {
        $lockKey = "lock:stock:{$sku}";
        $token   = bin2hex(random_bytes(8));
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $ok = $this->redis->set($lockKey, $token, 'NX', 'EX', 10);
            if ($ok === 'OK') {
                break;
            }
            usleep(50_000);
        }
        if ($this->redis->get($lockKey) !== $token) {
            $this->log->warning('reserve.lock_timeout', ['sku' => $sku]);
            return false;
        }

        try {
            $available = $this->repo->available($sku);
            if ($available < $qty) {
                $this->log->info('reserve.insufficient', ['sku' => $sku, 'need' => $qty]);
                return false;
            }
            $this->repo->decrement($sku, $qty);
            $this->repo->writeReservation($sku, $orderId, $qty);
            $this->log->info('reserve.ok', ['sku' => $sku, 'order' => $orderId]);
            return true;
        } finally {
            $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
            $this->redis->eval($script, 1, $lockKey, $token);
        }
    }
}
