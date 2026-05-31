<?php
declare(strict_types=1);

namespace App\Records;

use App\Auth\ViewerContext;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class AuditVisibleRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ViewerContext $viewer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, list<string>> $roleVisibility
     * @param list<string>                $columns
     * @return array<int, array<string, mixed>>
     */
    public function find(
        string $table,
        array $columns,
        string $foreignKey,
        int $foreignId,
        array $roleVisibility,
        string $orderBy
    ): array {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $foreignKey)) {
            throw new \InvalidArgumentException('Invalid identifier');
        }

        $role = $this->viewer->role();
        $visibilities = $roleVisibility[$role] ?? ['public'];
        $columnList = implode(', ', $columns);
        $placeholders = implode(',', array_map(static fn (int $i): string => ":vis{$i}", array_keys($visibilities)));

        $sql = "SELECT {$columnList}
                FROM {$table}
                WHERE deleted_at IS NULL
                  AND {$foreignKey} = :fk
                  AND visibility IN ({$placeholders})
                ORDER BY {$orderBy}";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':fk', $foreignId, PDO::PARAM_INT);
            foreach ($visibilities as $i => $v) {
                $stmt->bindValue(":vis{$i}", $v);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Audit-visible query failed', [
                'table' => $table,
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Unable to load {$table}", 0, $e);
        }
    }
}
