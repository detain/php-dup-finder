<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Mockery;
use Mockery\MockInterface;

abstract class TransactionalTestCase extends TestCase
{
    protected MockInterface $mockRepository;
    protected bool $transactionStarted = false;
    protected bool $transactionCommitted = false;
    protected bool $transactionRolledBack = false;

    protected function beginTransactionMock(string $repositoryClass): void
    {
        $this->$repositoryClass->shouldReceive('beginTransaction')
            ->once()
            ->andReturnNull();
        $this->transactionStarted = true;
    }

    protected function commitTransactionMock(string $repositoryClass): void
    {
        $this->$repositoryClass->shouldReceive('commit')
            ->once()
            ->andReturnNull();
        $this->transactionCommitted = true;
    }

    protected function rollbackTransactionMock(string $repositoryClass): void
    {
        $this->$repositoryClass->shouldReceive('rollback')
            ->zeroOrMoreTimes()
            ->andReturnNull();
        $this->transactionRolledBack = true;
    }

    protected function assertTransactionLifecycle(): void
    {
        $this->assertTrue($this->transactionStarted, 'Transaction should have been started');
        $this->assertTrue(
            $this->transactionCommitted || $this->transactionRolledBack,
            'Transaction should have been terminated'
        );
    }

    protected function teardownTransactions(): void
    {
        $this->transactionStarted = false;
        $this->transactionCommitted = false;
        $this->transactionRolledBack = false;
    }
}
