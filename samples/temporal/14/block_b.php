<?php
declare(strict_types=1);

namespace Amazon\DynamoDB\Service;

use Amazon\DynamoDB\Repository\TableRepository;
use Amazon\DynamoDB\Repository\ItemRepository;
use Amazon\DynamoDB\Entity\TableDescription;
use Amazon\DynamoDB\Entity\TransactWriteItem;
use Amazon\DynamoDB\Entity\BatchWriteItem;
use Amazon\DynamoDB\Exception\TransactionCanceledException;
use Amazon\DynamoDB\Service\ThroughputManager;
use Amazon\DynamoDB\Service\IndexManager;
use Psr\Log\LoggerInterface;

final class TransactionService
{
    private TableRepository $tableRepository;
    private ItemRepository $itemRepository;
    private ThroughputManager $throughputManager;
    private IndexManager $indexManager;
    private LoggerInterface $logger;

    public function __construct(
        TableRepository $tableRepository,
        ItemRepository $itemRepository,
        ThroughputManager $throughputManager,
        IndexManager $indexManager,
        LoggerInterface $logger
    ) {
        $this->tableRepository = $tableRepository;
        $this->itemRepository = $itemRepository;
        $this->throughputManager = $throughputManager;
        $this->indexManager = $indexManager;
        $this->logger = $logger;
    }

    public function beginTransaction(string $transactionId): TransactionContext
    {
        $this->logger->info('Beginning DynamoDB transaction', [
            'transaction_id' => $transactionId
        ]);

        $transactionLock = $this->tableRepository->acquireTransactionLock($transactionId);
        if ($transactionLock === null) {
            throw new \RuntimeException("Failed to acquire transaction lock: {$transactionId}");
        }

        $context = new TransactionContext($transactionId);
        $context->setLock($transactionLock);
        $context->setStartTime(new \DateTimeImmutable());

        $this->logger->debug('Transaction lock acquired', [
            'transaction_id' => $transactionId,
            'lock_id' => $transactionLock->getId()
        ]);

        return $context;
    }

    public function commitTransaction(TransactionContext $context): CommitResult
    {
        if (!$context->hasLock()) {
            throw new \RuntimeException('Transaction does not have an active lock');
        }

        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $context->getStartTime()->getTimestamp();
        if ($elapsed > 300) {
            throw new \RuntimeException('Transaction timeout exceeded (5 minutes)');
        }

        $this->logger->info('Committing transaction', [
            'transaction_id' => $context->getTransactionId(),
            'items_count' => count($context->getItems())
        ]);

        try {
            $writeItems = $this->buildWriteItems($context->getItems());
            $result = $this->itemRepository->executeTransactWrite($writeItems);

            $this->tableRepository->releaseTransactionLock($context->getLock()->getId());
            $context->clearLock();

            foreach ($context->getItems() as $item) {
                $this->throughputManager->recordConsumedCapacity(
                    $item['table_name'],
                    $item['item_size']
                );
            }

            $this->logger->info('Transaction committed successfully', [
                'transaction_id' => $context->getTransactionId(),
                'write_count' => count($writeItems)
            ]);

            return new CommitResult([
                'success' => true,
                'transaction_id' => $context->getTransactionId(),
                'committed_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Transaction commit failed, rolling back', [
                'transaction_id' => $context->getTransactionId(),
                'error' => $e->getMessage()
            ]);

            $this->rollbackTransaction($context);

            throw new TransactionCanceledException(
                "Transaction {$context->getTransactionId()} failed: " . $e->getMessage(),
                $e
            );
        }
    }

    public function rollbackTransaction(TransactionContext $context): RollbackResult
    {
        if ($context->hasLock()) {
            $this->tableRepository->releaseTransactionLock($context->getLock()->getId());
            $context->clearLock();
        }

        $this->logger->info('Transaction rolled back', [
            'transaction_id' => $context->getTransactionId()
        ]);

        return new RollbackResult([
            'success' => true,
            'transaction_id' => $context->getTransactionId(),
            'rolled_back_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    private function buildWriteItems(array $items): array
    {
        return array_map(function ($item) {
            return new TransactWriteItem([
                'table_name' => $item['table_name'],
                'operation' => $item['operation'],
                'key' => $item['key'],
                'item' => $item['data'] ?? null,
                'condition' => $item['condition'] ?? null
            ]);
        }, $items);
    }
}
