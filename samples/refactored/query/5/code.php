<?php
declare(strict_types=1);

namespace App\Permissions;

use mysqli;
use mysqli_sql_exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ActiveRolePermissionLookup
{
    public function __construct(
        private readonly mysqli $db,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param list<string> $roles
     * @return list<int>
     */
    public function findActiveIds(string $table, array $roles): array
    {
        if ($roles === []) {
            return [];
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table identifier: {$table}");
        }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $sql = "SELECT id FROM {$table} WHERE is_active = 1 AND role IN ({$placeholders})";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param(str_repeat('s', count($roles)), ...$roles);
            $stmt->execute();
            $result = $stmt->get_result();

            $ids = [];
            while (($row = $result->fetch_assoc()) !== null) {
                $ids[] = (int) $row['id'];
            }
            $stmt->close();

            return $ids;
        } catch (mysqli_sql_exception $e) {
            $this->logger->error('Active-role permission lookup failed', [
                'table' => $table,
                'roles' => $roles,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Permission lookup failed for {$table}", 0, $e);
        }
    }
}
