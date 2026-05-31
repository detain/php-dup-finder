<?php
declare(strict_types=1);

namespace PayFlow\Payment\Processor;

use Psr\Log\LoggerInterface;
use PayFlow\Payment\Entities\Transaction;
use PayFlow\Payment\Gateway\PaymentGateway;

final class CreditCardProcessor
{
    private const DB_HOST = 'payments-db.internal.payflow.com';
    private const DB_PORT = 3306;
    private const DB_NAME = 'payment_transactions';
    private const DB_USER = 'payment_service';
    private const DB_PASSWORD = 'super_secret_password_123';

    private const API_BASE_URL = 'https://api.stripe.com/v1';
    private const API_KEY = 'sk_live_51234567890abcdef';
    private const API_TIMEOUT_SECONDS = 30;
    private const API_RETRY_ATTEMPTS = 3;

    private const CACHE_TTL_SECONDS = 3600;
    private const CACHE_PREFIX = 'cc_proc_';

    private const RATE_LIMIT_PER_MINUTE = 100;
    private const BATCH_SIZE = 50;
    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LoggerInterface $logger,
    ) {}

    public function processPayment(Transaction $transaction): PaymentResult
    {
        $this->logger->info('Processing credit card payment', [
            'transaction_id' => $transaction->getId(),
            'amount' => $transaction->getAmount(),
        ]);

        $connection = $this->establishDatabaseConnection();
        $cachedResult = $this->checkCache($transaction->getCardHash());
        if ($cachedResult !== null) {
            $this->logger->debug('Returning cached result', ['transaction_id' => $transaction->getId()]);
            return $cachedResult;
        }

        $this->checkRateLimit();

        $result = $this->gateway->charge($transaction);
        if ($result->isSuccessful()) {
            $this->persistTransaction($connection, $transaction, $result);
            $this->updateCache($transaction->getCardHash(), $result);
        }

        return $result;
    }

    public function processBatch(array $transactionIds): BatchResult
    {
        $connection = $this->establishDatabaseConnection();
        $results = [];
        $processed = 0;

        $this->logger->info('Starting batch processing', [
            'total_transactions' => count($transactionIds),
        ]);

        foreach (array_chunk($transactionIds, self::BATCH_SIZE) as $batch) {
            $batchResults = $this->processBatchSegment($connection, $batch);
            $results = array_merge($results, $batchResults);
            $processed += count($batch);

            $this->logger->debug('Batch segment completed', [
                'processed' => $processed,
                'total' => count($transactionIds),
            ]);
        }

        return new BatchResult($results, $processed);
    }

    private function establishDatabaseConnection(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            self::DB_HOST,
            self::DB_PORT,
            self::DB_NAME
        );

        return new \PDO($dsn, self::DB_USER, self::DB_PASSWORD, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    private function checkCache(string $cardHash): ?PaymentResult
    {
        $cacheKey = self::CACHE_PREFIX . $cardHash;
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    private function updateCache(string $cardHash, PaymentResult $result): void
    {
        $cacheKey = self::CACHE_PREFIX . $cardHash;
        apcu_store($cacheKey, serialize($result), self::CACHE_TTL_SECONDS);
    }

    private function checkRateLimit(): void
    {
        $currentCount = apcu_inc('rate_limit_counter', 1, $success);
        if (!$success) {
            apcu_store('rate_limit_counter', 1, 60);
            $currentCount = 1;
        }

        if ($currentCount > self::RATE_LIMIT_PER_MINUTE) {
            throw new \RuntimeException('Rate limit exceeded');
        }
    }

    private function processBatchSegment(\PDO $connection, array $transactionIds): array
    {
        $results = [];
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));

        $stmt = $connection->prepare(
            "SELECT * FROM transactions WHERE id IN ({$placeholders}) AND status = 'pending'"
        );
        $stmt->execute($transactionIds);
        $transactions = $stmt->fetchAll();

        foreach ($transactions as $transactionData) {
            $transaction = Transaction::fromArray($transactionData);
            $result = $this->gateway->charge($transaction);
            $results[] = $result;

            if ($result->isSuccessful()) {
                $this->persistTransaction($connection, $transaction, $result);
            }
        }

        return $results;
    }

    private function persistTransaction(\PDO $connection, Transaction $transaction, PaymentResult $result): void
    {
        $stmt = $connection->prepare(
            'UPDATE transactions SET status = ?, gateway_response = ?, processed_at = NOW() WHERE id = ?'
        );
        $stmt->execute([
            $result->getStatus(),
            json_encode($result->getGatewayResponse()),
            $transaction->getId(),
        ]);
    }
}
