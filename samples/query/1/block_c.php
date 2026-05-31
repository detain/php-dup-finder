<?php
declare(strict_types=1);

namespace App\Repository\Project;

use App\Context\TenantContext;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ProjectListRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantContext $tenant,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveProjects(int $page = 1, int $perPage = 20): array
    {
        $tenantId = $this->tenant->currentId();
        if ($tenantId <= 0) {
            throw new RuntimeException('Cannot list projects without a tenant');
        }

        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT id, code, name, owner_id, started_at
                FROM projects
                WHERE deleted_at IS NULL
                  AND tenant_id = :tenant_id
                ORDER BY started_at DESC
                LIMIT :limit OFFSET :offset';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('Project list query failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load projects', 0, $e);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'owner_id' => (int) $row['owner_id'],
            'started_at' => (string) $row['started_at'],
        ], $rows ?: []);
    }
}
