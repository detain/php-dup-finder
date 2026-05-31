<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Orders;

use App\Commands\UpdateOrder\UpdateOrderCommand;
use App\Commands\UpdateOrder\UpdateOrderHandler;
use App\Domain\OrderRepository;
use App\Events\OrderUpdated;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class UpdateOrderHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Mockery\MockInterface $repo;
    private Mockery\MockInterface $events;
    private UpdateOrderHandler $handler;

    protected function setUp(): void
    {
        $this->repo   = Mockery::mock(OrderRepository::class);
        $this->events = Mockery::mock(EventDispatcherInterface::class);
        $this->handler = new UpdateOrderHandler($this->repo, $this->events);
    }

    public function testHandleUpdatesAndDispatches(): void
    {
        $command = new UpdateOrderCommand(orderId: 7, status: 'shipped', notes: 'urgent');

        $this->repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(fn ($o) => $o->id === 7 && $o->status === 'shipped'))
            ->andReturn(7);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(OrderUpdated::class));

        $id = $this->handler->handle($command);

        $this->assertSame(7, $id);
    }
}
