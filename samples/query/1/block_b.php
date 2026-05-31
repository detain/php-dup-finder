<?php
declare(strict_types=1);

namespace App\Repository\Invoice;

use App\Context\TenantContext;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class InvoiceListRepository
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
    public function listOpenInvoices(int $page = 1, int $perPage = 50): array
    {
        $tenantId = $this->tenant->currentId();
        if ($tenantId <= 0) {
            throw new RuntimeException('Tenant context missing for invoice query');
        }

        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT id, number, total_cents, currency, due_at, status
                FROM invoices
                WHERE deleted_at IS NULL
                  AND tenant_id = :tenant_id
                ORDER BY due_at ASC
                LIMIT :limit OFFSET :offset';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('Invoice list query failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load invoices', 0, $e);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'total_cents' => (int) $row['total_cents'],
            'currency' => (string) $row['currency'],
            'due_at' => (string) $row['due_at'],
            'status' => (string) $row['status'],
        ], $rows ?: []);
    }
}
