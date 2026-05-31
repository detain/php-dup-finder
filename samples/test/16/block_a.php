<?php

declare(strict_types=1);

namespace Tests\Unit\User;

use PHPUnit\Framework\TestCase;
use App\Repositories\UserRepository;
use App\Models\User;
use App\Services\UserService;
use Mockery;
use Mockery\MockInterface;

class UserServiceTest extends TestCase
{
    private UserService $userService;
    private MockInterface $userRepository;
    private MockInterface $eventDispatcher;
    private MockInterface $mailService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = Mockery::mock(UserRepository::class);
        $this->eventDispatcher = Mockery::mock(\App\Services\EventDispatcher::class);
        $this->mailService = Mockery::mock(\App\Services\MailService::class);

        $this->userService = new UserService(
            $this->userRepository,
            $this->eventDispatcher,
            $this->mailService
        );

        // Configure default repository behavior
        $this->userRepository->shouldReceive('getConnection')
            ->andReturn(Mockery::mock(\Doctrine\DBAL\Connection::class));
        $this->userRepository->shouldReceive('getTableName')
            ->andReturn('users');
        $this->userRepository->shouldReceive('getModelClass')
            ->andReturn(User::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createMockUser(array $attributes = []): User
    {
        $defaults = [
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'status' => 'active',
            'created_at' => new \DateTimeImmutable(),
            'updated_at' => new \DateTimeImmutable(),
        ];

        return new User(array_merge($defaults, $attributes));
    }

    public function testFindsUserByEmail(): void
    {
        $user = $this->createMockUser(['email' => 'search@example.com']);

        $this->userRepository
            ->shouldReceive('findByEmail')
            ->with('search@example.com')
            ->once()
            ->andReturn($user);

        $result = $this->userService->findByEmail('search@example.com');

        $this->assertSame($user, $result);
    }

    public function testFindsUserById(): void
    {
        $user = $this->createMockUser(['id' => 42]);

        $this->userRepository
            ->shouldReceive('findById')
            ->with(42)
            ->once()
            ->andReturn($user);

        $result = $this->userService->findById(42);

        $this->assertSame($result, $user);
    }

    public function testSavesUserWithUpdatedTimestamp(): void
    {
        $user = $this->createMockUser(['id' => 1]);

        $this->userRepository
            ->shouldReceive('save')
            ->with(Mockery::on(function ($arg) use ($user) {
                return $arg instanceof User && $arg->id === $user->id;
            }))
            ->once()
            ->andReturn($user);

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->with(Mockery::type(\App\Events\UserUpdated::class))
            ->once();

        $result = $this->userService->save($user);

        $this->assertSame($user, $result);
    }

    public function testDeletesUserAndDispatchesEvent(): void
    {
        $user = $this->createMockUser(['id' => 99]);

        $this->userRepository
            ->shouldReceive('delete')
            ->with(99)
            ->once()
            ->andReturn(true);

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->with(Mockery::on(function ($event) use ($user) {
                return $event instanceof \App\Events\UserDeleted
                    && $event->getUserId() === 99;
            }))
            ->once();

        $result = $this->userService->delete(99);

        $this->assertTrue($result);
    }

    public function testSendsWelcomeEmailOnUserCreation(): void
    {
        $user = $this->createMockUser(['email' => 'newuser@example.com']);

        $this->userRepository
            ->shouldReceive('save')
            ->once()
            ->andReturn($user);

        $this->mailService
            ->shouldReceive('send')
            ->with(
                'welcome',
                Mockery::type(\App\Mail\Mailable::class),
                $user->email
            )
            ->once();

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->once();

        $this->userService->create($user);
    }
}
