<?php
declare(strict_types=1);

namespace App\Billing\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use PDO;

final class InvoiceRepository
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
    }

    public function findActiveInvoices(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $startTime = microtime(true);
        
        $conditions = ['deleted_at IS NULL'];
        $params = [];
        
        if (isset($filters['status']) && $filters['status'] !== '') {
            $conditions[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        
        if (isset($filters['customer_id']) && $filters['customer_id'] > 0) {
            $conditions[] = 'customer_id = :customer_id';
            $params[':customer_id'] = $filters['customer_id'];
        }
        
        if (isset($filters['invoice_number']) && $filters['invoice_number'] !== '') {
            $conditions[] = 'invoice_number LIKE :invoice_number';
            $params[':invoice_number'] = '%' . $filters['invoice_number'] . '%';
        }
        
        if (isset($filters['min_amount']) && $filters['min_amount'] > 0) {
            $conditions[] = 'total_amount >= :min_amount';
            $params[':min_amount'] = $filters['min_amount'];
        }
        
        if (isset($filters['max_amount']) && $filters['max_amount'] > 0) {
            $conditions[] = 'total_amount <= :max_amount';
            $params[':max_amount'] = $filters['max_amount'];
        }
        
        if (isset($filters['due_date_from'])) {
            $conditions[] = 'due_date >= :due_date_from';
            $params[':due_date_from'] = $filters['due_date_from'];
        }
        
        if (isset($filters['due_date_to'])) {
            $conditions[] = 'due_date <= :due_date_to';
            $params[':due_date_to'] = $filters['due_date_to'];
        }
        
        if (isset($filters['created_after'])) {
            $conditions[] = 'created_at >= :created_after';
            $params[':created_after'] = $filters['created_after'];
        }
        
        if (isset($filters['created_before'])) {
            $conditions[] = 'created_at <= :created_before';
            $params[':created_before'] = $filters['created_before'];
        }
        
        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortDir = strtoupper($filters['sort_dir'] ?? 'DESC');
        $allowedSorts = ['created_at', 'due_date', 'total_amount', 'status', 'invoice_number'];
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'created_at';
        }
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'DESC';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countSql = "SELECT COUNT(*) FROM invoices WHERE {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();
        
        $sql = "SELECT id, invoice_number, customer_id, status, subtotal, 
                       tax_amount, total_amount, currency, due_date,
                       issued_at, paid_at, created_at
                FROM invoices 
                WHERE {$whereClause}
                ORDER BY {$sortField} {$sortDir}
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $elapsed = microtime(true) - $startTime;
        $this->logger->info('Active invoices query executed', [
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'elapsed_ms' => round($elapsed * 1000, 2)
        ]);
        
        return [
            'data' => $invoices,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage)
            ]
        ];
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT id, invoice_number, customer_id, status, subtotal,
                       tax_amount, total_amount, currency, due_date,
                       issued_at, paid_at, created_at, notes
                FROM invoices 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function markAsPaid(int $id, string $paymentReference): bool
    {
        $sql = "UPDATE invoices SET 
                    status = 'paid', 
                    paid_at = NOW(), 
                    payment_reference = :payment_reference,
                    updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':payment_reference', $paymentReference);
        
        $result = $stmt->execute();
        
        if ($result) {
            $this->logger->info('Invoice marked as paid', [
                'invoice_id' => $id,
                'payment_reference' => $paymentReference
            ]);
        }
        
        return $result;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $sql = "UPDATE invoices SET deleted_at = NOW(), deleted_by = :deleted_by 
                WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':deleted_by', $deletedBy, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
}
