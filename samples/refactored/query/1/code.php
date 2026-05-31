<?php
declare(strict_types=1);

namespace App\Repository;

use App\Context\TenantContext;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

abstract class TenantScopedRepository
{
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly TenantContext $tenant,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param list<string> $columns
     * @return array<int, array<string, mixed>>
     */
    protected function listTenantScoped(
        string $table,
        array $columns,
        string $orderBy,
        int $page,
        int $perPage
    ): array {
        $tenantId = $this->tenant->currentId();
        if ($tenantId <= 0) {
            throw new RuntimeException('Tenant context not initialised');
        }

        $offset = max(0, ($page - 1) * $perPage);
        $columnList = implode(', ', $columns);

        $sql = "SELECT {$columnList}
                FROM {$table}
                WHERE deleted_at IS NULL
                  AND tenant_id = :tenant_id
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Tenant-scoped query failed', [
                'table' => $table,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Unable to load {$table}", 0, $e);
        }
    }
}
