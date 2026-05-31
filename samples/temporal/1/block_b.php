<?php
declare(strict_types=1);

namespace Warehouse\Inventory\Transfer;

use Predis\Client;
use Psr\Log\LoggerInterface;

final class WarehouseTransfer
{
    public function __construct(
        private Client $redis,
        private TransferLedger $ledger,
        private LoggerInterface $log,
    ) {}

    public function move(string $sku, string $from, string $to, int $qty): bool
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
            $this->log->warning('transfer.lock_timeout', ['sku' => $sku]);
            return false;
        }

        try {
            $atSource = $this->ledger->balance($sku, $from);
            if ($atSource < $qty) {
                $this->log->info('transfer.short', ['sku' => $sku, 'from' => $from]);
                return false;
            }
            $this->ledger->debit($sku, $from, $qty);
            $this->ledger->credit($sku, $to, $qty);
            $this->ledger->note($sku, $from, $to, $qty);
            $this->log->info('transfer.ok', ['sku' => $sku]);
            return true;
        } finally {
            $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
            $this->redis->eval($script, 1, $lockKey, $token);
        }
    }
}
