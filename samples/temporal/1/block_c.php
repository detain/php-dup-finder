<?php
declare(strict_types=1);

namespace Warehouse\Inventory\Restock;

use Predis\Client;
use Psr\Log\LoggerInterface;

final class RestockHandler
{
    public function __construct(
        private Client $redis,
        private StockRepository $repo,
        private PurchaseOrders $po,
        private LoggerInterface $log,
    ) {}

    public function receive(string $sku, int $qty, string $poNumber): bool
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
            $this->log->warning('restock.lock_timeout', ['sku' => $sku]);
            return false;
        }

        try {
            if (!$this->po->isOpen($poNumber)) {
                $this->log->info('restock.po_closed', ['po' => $poNumber]);
                return false;
            }
            $this->repo->increment($sku, $qty);
            $this->po->markReceived($poNumber, $sku, $qty);
            $this->log->info('restock.ok', ['sku' => $sku, 'qty' => $qty]);
            return true;
        } finally {
            $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
            $this->redis->eval($script, 1, $lockKey, $token);
        }
    }
}
