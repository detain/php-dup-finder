<?php

declare(strict_types=1);

namespace Tests\Shared\Events;

use PHPUnit\Framework\TestCase;
use App\Services\EventDispatcher;
use Mockery;
use Mockery\MockInterface;

abstract class EventDispatcherTestCase extends TestCase
{
    protected EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new EventDispatcher();
        $this->registerListeners();
    }

    abstract protected function registerListeners(): void;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function assertEventDispatched(string $eventClass, callable $assertions): void
    {
        $event = $this->createEventMock($eventClass);
        $assertions($event);
        $this->dispatcher->dispatch($event);
    }

    protected function createEventMock(string $eventClass): object
    {
        return new $eventClass($this->getEventData());
    }

    protected function getEventData(): array
    {
        return [
            'id' => 1,
            'created_at' => new \DateTimeImmutable(),
        ];
    }
}

class UserEventDispatcherTest extends EventDispatcherTestCase
{
    private $mailService;
    private $searchService;

    protected function setUp(): void
    {
        $this->mailService = Mockery::mock(\App\Services\MailService::class);
        $this->searchService = Mockery::mock(\App\Services\SearchService::class);
        parent::setUp();
    }

    protected function registerListeners(): void
    {
        $this->dispatcher->addListener(
            \App\Events\UserRegistered::class,
            new \App\Listeners\SendWelcomeEmailListener($this->mailService)
        );
    }

    public function testDispatchesUserRegisteredEvent(): void
    {
        $this->mailService
            ->shouldReceive('send')
            ->once();

        parent::assertEventDispatched(\App\Events\UserRegistered::class, function ($event) {
            // Additional assertions
        });
    }
}
