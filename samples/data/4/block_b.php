<?php
declare(strict_types=1);

namespace App\Admin\Repositories;

use App\Database\Connection;
use App\Admin\Administrator;

final class AdminRepository
{
    public function __construct(private Connection $db) {}

    public function provision(string $email, string $plainPassword, array $roles): Administrator
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid admin email');
        }

        if (strlen($plainPassword) < 16) {
            throw new \InvalidArgumentException('Admin password must be at least 16 characters');
        }

        if ($roles === []) {
            throw new \InvalidArgumentException('Admin must have at least one role');
        }

        $allowedRoles = ['superuser', 'support', 'auditor', 'billing'];
        foreach ($roles as $role) {
            if (!in_array($role, $allowedRoles, true)) {
                throw new \InvalidArgumentException("Unknown role: {$role}");
            }
        }

        $passwordHash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($passwordHash === false) {
            throw new \RuntimeException('Admin password hashing failed');
        }

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO administrators (email, password_hash, created_at, must_rotate_password)
                 VALUES (?, ?, NOW(), 1)',
                [$email, $passwordHash]
            );

            $adminId = $this->db->lastInsertId();

            foreach ($roles as $role) {
                $this->db->execute(
                    'INSERT INTO administrator_roles (admin_id, role) VALUES (?, ?)',
                    [$adminId, $role]
                );
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return new Administrator($adminId, $email, $roles);
    }
}
