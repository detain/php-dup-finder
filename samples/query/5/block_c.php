<?php
declare(strict_types=1);

namespace App\Permissions\Api;

use mysqli;
use mysqli_sql_exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ApiKeyPermissionLookup
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
    public function activeKeysWithRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $sql = "SELECT id
                FROM api_keys
                WHERE is_active = 1
                  AND role IN ({$placeholders})";

        try {
            $stmt = $this->db->prepare($sql);
            $types = str_repeat('s', count($roles));
            $stmt->bind_param($types, ...$roles);
            $stmt->execute();
            $result = $stmt->get_result();

            $ids = [];
            while (($row = $result->fetch_assoc()) !== null) {
                $ids[] = (int) $row['id'];
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $this->logger->error('Active API key permission lookup failed', [
                'roles' => $roles,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('API key permission lookup failed', 0, $e);
        }

        return $ids;
    }
}
