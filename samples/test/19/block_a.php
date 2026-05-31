<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use App\Services\EventDispatcher;
use App\Events\UserRegistered;
use App\Events\UserUpdated;
use App\Events\UserDeleted;
use App\Events\UserLoggedIn;
use App\Events\UserPasswordChanged;
use App\Listeners\SendWelcomeEmailListener;
use App\Listeners\UpdateUserSearchIndexListener;
use App\Listeners\LogUserActivityListener;
use App\Listeners\NotifyAdminsListener;
use Mockery;
use Mockery\MockInterface;

class UserEventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private MockInterface $mailService;
    private MockInterface $searchService;
    private MockInterface $logger;
    private MockInterface $adminNotifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailService = Mockery::mock(\App\Services\MailService::class);
        $this->searchService = Mockery::mock(\App\Services\SearchService::class);
        $this->logger = Mockery::mock(\App\Services\Logger::class);
        $this->adminNotifier = Mockery::mock(\App\Services\AdminNotifier::class);

        $this->dispatcher = new EventDispatcher();

        // Register event listeners
        $this->dispatcher->addListener(
            UserRegistered::class,
            new SendWelcomeEmailListener($this->mailService)
        );

        $this->dispatcher->addListener(
            UserRegistered::class,
            new UpdateUserSearchIndexListener($this->searchService)
        );

        $this->dispatcher->addListener(
            UserRegistered::class,
            new NotifyAdminsListener($this->adminNotifier)
        );

        $this->dispatcher->addListener(
            UserUpdated::class,
            new UpdateUserSearchIndexListener($this->searchService)
        );

        $this->dispatcher->addListener(
            UserLoggedIn::class,
            new LogUserActivityListener($this->logger)
        );

        $this->dispatcher->addListener(
            UserPasswordChanged::class,
            new LogUserActivityListener($this->logger)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testDispatchesUserRegisteredEvent(): void
    {
        $user = ['id' => 1, 'email' => 'new@example.com', 'name' => 'New User'];

        $this->mailService
            ->shouldReceive('send')
            ->with(
                'welcome',
                Mockery::type(\App\Mail\Mailable::class),
                'new@example.com'
            )
            ->once();

        $this->searchService
            ->shouldReceive('index')
            ->with('users', 1)
            ->once();

        $this->adminNotifier
            ->shouldReceive('notify')
            ->with(
                Mockery::on(function ($notification) use ($user) {
                    return $notification instanceof \App\Notifications\UserRegistrationNotification
                        && $notification->getUser()['id'] === 1;
                })
            )
            ->once();

        $event = new UserRegistered($user);
        $this->dispatcher->dispatch($event);
    }

    public function testDispatchesUserUpdatedEvent(): void
    {
        $user = ['id' => 1, 'email' => 'updated@example.com', 'name' => 'Updated Name'];

        $this->searchService
            ->shouldReceive('index')
            ->with('users', 1)
            ->once();

        $event = new UserUpdated($user, ['name' => 'Old Name']);
        $this->dispatcher->dispatch($event);
    }

    public function testDispatchesUserLoggedInEvent(): void
    {
        $user = ['id' => 1, 'email' => 'user@example.com'];
        $timestamp = new \DateTimeImmutable();

        $this->logger
            ->shouldReceive('info')
            ->with(
                Mockery::on(function ($message) {
                    return strpos($message, 'User logged in') !== false;
                }),
                Mockery::type('array')
            )
            ->once();

        $event = new UserLoggedIn($user, '127.0.0.1', $timestamp);
        $this->dispatcher->dispatch($event);
    }

    public function testDispatchesUserPasswordChangedEvent(): void
    {
        $user = ['id' => 1, 'email' => 'user@example.com'];
        $timestamp = new \DateTimeImmutable();

        $this->logger
            ->shouldReceive('info')
            ->with(
                Mockery::on(function ($message) {
                    return strpos($message, 'Password changed') !== false;
                }),
                Mockery::type('array')
            )
            ->once();

        $event = new UserPasswordChanged($user, $timestamp);
        $this->dispatcher->dispatch($event);
    }

    public function testListenersAreCalledInRegistrationOrder(): void
    {
        $callOrder = [];

        $listener1 = new class($callOrder) {
            public function __construct(private array &$order) {}
            public function __invoke($event) { $this->order[] = 'listener1'; }
        };

        $listener2 = new class($callOrder) {
            public function __construct(private array &$order) {}
            public function __invoke($event) { $this->order[] = 'listener2'; }
        };

        $this->dispatcher->addListener(UserDeleted::class, $listener1);
        $this->dispatcher->addListener(UserDeleted::class, $listener2);

        $event = new UserDeleted(['id' => 1]);
        $this->dispatcher->dispatch($event);

        $this->assertEquals(['listener1', 'listener2'], $callOrder);
    }
}
