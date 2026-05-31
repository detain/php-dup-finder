<?php
declare(strict_types=1);

namespace App\Auth;

final class PasswordPolicy
{
    public const BCRYPT_COST = 12;
    public const MIN_USER_LENGTH = 12;
    public const MIN_ADMIN_LENGTH = 16;

    public static function hash(string $plain): string
    {
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed');
        }
        return $hash;
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }
}

namespace App\Auth\Repositories;

use App\Auth\PasswordPolicy;
use App\Database\Connection;

final class UserRepository
{
    public function __construct(private Connection $db) {}

    public function create(string $email, string $password, string $name): int
    {
        $this->db->execute(
            'INSERT INTO users (email, password_hash, name, created_at) VALUES (?, ?, ?, NOW())',
            [$email, PasswordPolicy::hash($password), $name]
        );
        return $this->db->lastInsertId();
    }
}

namespace App\Admin\Repositories;

use App\Auth\PasswordPolicy;
use App\Database\Connection;

final class AdminRepository
{
    public function __construct(private Connection $db) {}

    public function provision(string $email, string $password): int
    {
        $this->db->execute(
            'INSERT INTO administrators (email, password_hash, created_at) VALUES (?, ?, NOW())',
            [$email, PasswordPolicy::hash($password)]
        );
        return $this->db->lastInsertId();
    }
}

namespace App\Auth\PasswordReset;

use App\Auth\PasswordPolicy;
use App\Database\Connection;

final class PasswordResetService
{
    public function __construct(private Connection $db) {}

    public function completeReset(int $userId, string $newPassword): void
    {
        $this->db->execute(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [PasswordPolicy::hash($newPassword), $userId]
        );
    }
}
