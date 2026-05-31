<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Repository;

use App\Domain\Database\DatabaseConnection;
use App\Domain\UserManagement\Entity\User;
use App\Domain\UserManagement\Specification\UserSpecification;

/**
 * User repository implementation with manual database connection injection.
 * The DatabaseConnection is manually injected here, duplicated across
 * all repository classes.
 */
class UserRepository implements UserRepositoryInterface
{
    private DatabaseConnection $db;
    private string $table = 'users';

    public function __construct(DatabaseConnection $db)
    {
        $this->db = $db;
    }

    public function findById(string $id): ?User
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";

        $result = $this->db->query($sql, [$id]);

        if ($result->numRows() === 0) {
            return null;
        }

        return $this->hydrateUser($result->fetch());
    }

    public function findByEmail(string $email): ?User
    {
        $sql = "SELECT * FROM {$this->table} WHERE email = ? LIMIT 1";

        $result = $this->db->query($sql, [$email]);

        if ($result->numRows() === 0) {
            return null;
        }

        return $this->hydrateUser($result->fetch());
    }

    public function findBySpecification(UserSpecification $spec): array
    {
        $conditions = [];
        $params = [];

        if ($spec->getEmail() !== null) {
            $conditions[] = 'email = ?';
            $params[] = $spec->getEmail();
        }

        if ($spec->getStatus() !== null) {
            $conditions[] = 'status = ?';
            $params[] = $spec->getStatus();
        }

        if ($spec->getCreatedAfter() !== null) {
            $conditions[] = 'created_at >= ?';
            $params[] = $spec->getCreatedAfter()->format('Y-m-d H:i:s');
        }

        if ($spec->getCreatedBefore() !== null) {
            $conditions[] = 'created_at <= ?';
            $params[] = $spec->getCreatedBefore()->format('Y-m-d H:i:s');
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT * FROM {$this->table} {$whereClause} ORDER BY created_at DESC";

        if ($spec->getLimit() !== null) {
            $sql .= " LIMIT " . (int) $spec->getLimit();
        }

        if ($spec->getOffset() !== null) {
            $sql .= " OFFSET " . (int) $spec->getOffset();
        }

        $result = $this->db->query($sql, $params);

        $users = [];
        while ($row = $result->fetch()) {
            $users[] = $this->hydrateUser($row);
        }

        return $users;
    }

    public function save(User $user): User
    {
        if ($user->getId() === null) {
            return $this->insert($user);
        }

        return $this->update($user);
    }

    private function insert(User $user): User
    {
        $sql = "INSERT INTO {$this->table} (
            email, first_name, last_name, phone, password_hash,
            status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $params = [
            $user->getEmail(),
            $user->getFirstName(),
            $user->getLastName(),
            $user->getPhoneNumber(),
            $user->getPasswordHash(),
            $user->getStatus(),
        ];

        $this->db->query($sql, $params);

        $id = $this->db->getLastInsertId();
        $user->setId($id);

        return $user;
    }

    private function update(User $user): User
    {
        $sql = "UPDATE {$this->table} SET
            email = ?,
            first_name = ?,
            last_name = ?,
            phone = ?,
            status = ?,
            updated_at = NOW()
        WHERE id = ?";

        $params = [
            $user->getEmail(),
            $user->getFirstName(),
            $user->getLastName(),
            $user->getPhoneNumber(),
            $user->getStatus(),
            $user->getId(),
        ];

        $this->db->query($sql, $params);

        return $user;
    }

    public function delete(string $id): void
    {
        $sql = "DELETE FROM {$this->table} WHERE id = ?";

        $this->db->query($sql, [$id]);
    }

    private function hydrateUser(array $row): User
    {
        return new User(
            id: $row['id'],
            email: $row['email'],
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            phoneNumber: $row['phone'],
            passwordHash: $row['password_hash'],
            status: $row['status'],
            createdAt: new \DateTimeImmutable($row['created_at']),
        );
    }
}
