<?php
declare(strict_types=1);

namespace App\Repository\Customer;

use App\Context\TenantContext;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class CustomerListRepository
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
    public function listActiveCustomers(int $page = 1, int $perPage = 25): array
    {
        $tenantId = $this->tenant->currentId();
        if ($tenantId <= 0) {
            throw new RuntimeException('Tenant context not initialised');
        }

        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT id, display_name, email, status, created_at
                FROM customers
                WHERE deleted_at IS NULL
                  AND tenant_id = :tenant_id
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('Customer list query failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load customers', 0, $e);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['display_name'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ], $rows ?: []);
    }
}
