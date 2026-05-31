<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Database\Connection;
use App\Auth\User;

final class UserRepository
{
    public function __construct(private Connection $db) {}

    public function create(string $email, string $plainPassword, string $name): User
    {
        $email = mb_strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address');
        }

        if (strlen($plainPassword) < 12) {
            throw new \InvalidArgumentException('Password must be at least 12 characters');
        }

        $existing = $this->db->fetchOne(
            'SELECT id FROM users WHERE email = ?',
            [$email]
        );

        if ($existing !== null) {
            throw new \DomainException('Email already registered');
        }

        $hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed');
        }

        $this->db->execute(
            'INSERT INTO users (email, password_hash, name, created_at, status)
             VALUES (?, ?, ?, NOW(), ?)',
            [$email, $hash, $name, 'pending_verification']
        );

        $userId = $this->db->lastInsertId();

        $this->db->execute(
            'INSERT INTO user_audit_log (user_id, action, ip, created_at)
             VALUES (?, ?, ?, NOW())',
            [$userId, 'created', $_SERVER['REMOTE_ADDR'] ?? 'cli']
        );

        return new User(
            id:    $userId,
            email: $email,
            name:  $name,
        );
    }
}
