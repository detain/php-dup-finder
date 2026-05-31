<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Users;

use App\Commands\CreateUser\CreateUserCommand;
use App\Commands\CreateUser\CreateUserHandler;
use App\Domain\UserRepository;
use App\Events\UserCreated;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class CreateUserHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Mockery\MockInterface $repo;
    private Mockery\MockInterface $events;
    private CreateUserHandler $handler;

    protected function setUp(): void
    {
        $this->repo   = Mockery::mock(UserRepository::class);
        $this->events = Mockery::mock(EventDispatcherInterface::class);
        $this->handler = new CreateUserHandler($this->repo, $this->events);
    }

    public function testHandleStoresAndDispatches(): void
    {
        $command = new CreateUserCommand(email: 'a@b.test', name: 'Alice', role: 'member');

        $this->repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(fn ($u) => $u->email === 'a@b.test'))
            ->andReturn(42);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(UserCreated::class));

        $id = $this->handler->handle($command);

        $this->assertSame(42, $id);
    }
}
