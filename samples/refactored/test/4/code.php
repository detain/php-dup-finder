<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

abstract class AbstractHandlerTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected Mockery\MockInterface $repo;
    protected Mockery\MockInterface $events;

    /** @return class-string */
    abstract protected function repositoryClass(): string;

    /** @return class-string */
    abstract protected function eventClass(): string;

    protected function setUp(): void
    {
        $this->repo   = Mockery::mock($this->repositoryClass());
        $this->events = Mockery::mock(EventDispatcherInterface::class);
    }

    protected function expectSaveAndDispatch(callable $entityMatcher, int $returnId): void
    {
        $this->repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on($entityMatcher))
            ->andReturn($returnId);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type($this->eventClass()));
    }
}
