<?php

declare(strict_types=1);

namespace Tests\Shared\Fixtures;

use PHPUnit\Framework\TestCase;
use Mockery;
use Mockery\MockInterface;

trait RepositoryMockSetupTrait
{
    abstract protected function getRepositoryClass(): string;

    abstract protected function getModelClass(): string;

    abstract protected function getTableName(): string;

    protected function createRepositoryMock(string $className): MockInterface
    {
        $mock = Mockery::mock($className);

        $mock->shouldReceive('getConnection')
            ->andReturn(Mockery::mock(\Doctrine\DBAL\Connection::class));

        $mock->shouldReceive('getTableName')
            ->andReturn($this->getTableName());

        $mock->shouldReceive('getModelClass')
            ->andReturn($this->getModelClass());

        return $mock;
    }

    protected function createModelInstance(array $attributes = []): object
    {
        $class = $this->getModelClass();
        $defaults = [
            'id' => 1,
            'created_at' => new \DateTimeImmutable(),
            'updated_at' => new \DateTimeImmutable(),
        ];

        return new $class(array_merge($defaults, $attributes));
    }
}

class UserRepositoryMockSetup extends TestCase
{
    use RepositoryMockSetupTrait;

    protected function getRepositoryClass(): string
    {
        return UserRepository::class;
    }

    protected function getModelClass(): string
    {
        return User::class;
    }

    protected function getTableName(): string
    {
        return 'users';
    }

    public function testExample(): void
    {
        $userRepo = $this->createRepositoryMock(UserRepository::class);
        // Use the mock...
    }
}
