<?php
declare(strict_types=1);

namespace App\Cqrs\Users;

final class RegisterUserCommand
{
    public function __construct(public string $email, public string $password) {}
}

final class RegisterUserResult
{
    public function __construct(public int $userId, public bool $isNew) {}
}

final class RegisterUserHandler
{
    public function __construct(private \PDO $pdo, private \Psr\Log\LoggerInterface $log) {}

    public function handle(RegisterUserCommand $cmd): RegisterUserResult
    {
        $this->log->info('handling RegisterUser', ['email' => $cmd->email]);
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$cmd->email]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return new RegisterUserResult((int) $existing, false);
        }
        $hash = password_hash($cmd->password, PASSWORD_BCRYPT);
        $ins = $this->pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $ins->execute([$cmd->email, $hash]);
        $id = (int) $this->pdo->lastInsertId();
        $this->log->info('user registered', ['id' => $id]);
        return new RegisterUserResult($id, true);
    }
}

final class UserBus
{
    public function __construct(private RegisterUserHandler $handler) {}

    public function dispatch(RegisterUserCommand $cmd): RegisterUserResult
    {
        try {
            return $this->handler->handle($cmd);
        } catch (\Throwable $e) {
            throw new \RuntimeException('RegisterUser failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
