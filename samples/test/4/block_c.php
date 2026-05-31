<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Projects;

use App\Commands\DeleteProject\DeleteProjectCommand;
use App\Commands\DeleteProject\DeleteProjectHandler;
use App\Domain\ProjectRepository;
use App\Events\ProjectDeleted;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class DeleteProjectHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Mockery\MockInterface $repo;
    private Mockery\MockInterface $events;
    private DeleteProjectHandler $handler;

    protected function setUp(): void
    {
        $this->repo   = Mockery::mock(ProjectRepository::class);
        $this->events = Mockery::mock(EventDispatcherInterface::class);
        $this->handler = new DeleteProjectHandler($this->repo, $this->events);
    }

    public function testHandleDeletesAndDispatches(): void
    {
        $command = new DeleteProjectCommand(projectId: 99, reason: 'archived');

        $this->repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(fn ($p) => $p->id === 99 && $p->deleted === true))
            ->andReturn(99);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(ProjectDeleted::class));

        $id = $this->handler->handle($command);

        $this->assertSame(99, $id);
    }
}
