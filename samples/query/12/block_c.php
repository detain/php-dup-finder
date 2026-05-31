<?php
declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Security\CurrentUser;
use PDO;

final class SystemReportRepository
{
    private PDO $db;
    private LoggerInterface $logger;
    private CurrentUser $currentUser;

    public function __construct(
        Connection $connection, 
        LoggerInterface $logger,
        CurrentUser $currentUser
    ) {
        $this->db = $connection->getPdo();
        $this->logger = $logger;
        $this->currentUser = $currentUser;
    }

    public function getPaginatedReports(array $params = []): array
    {
        $startTime = microtime(true);
        
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(10, (int)($params['per_page'] ?? 25)));
        $sortBy = $params['sort_by'] ?? 'generated_at';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $allowedSortColumns = ['generated_at', 'report_type', 'status', 'generated_by', 'format'];
        if (!in_array($sortBy, $allowedSortColumns, true)) {
            $sortBy = 'generated_at';
        }
        
        $conditions = ['1=1'];
        $bindings = [];
        
        if (!empty($params['report_type'])) {
            $conditions[] = 'report_type = :report_type';
            $bindings[':report_type'] = $params['report_type'];
        }
        
        if (!empty($params['status'])) {
            $conditions[] = 'status = :status';
            $bindings[':status'] = $params['status'];
        }
        
        if (!empty($params['format'])) {
            $conditions[] = 'format = :format';
            $bindings[':format'] = $params['format'];
        }
        
        if (!empty($params['generated_by'])) {
            $conditions[] = 'generated_by = :generated_by';
            $bindings[':generated_by'] = (int)$params['generated_by'];
        }
        
        if (!empty($params['date_from'])) {
            $conditions[] = 'generated_at >= :date_from';
            $bindings[':date_from'] = $params['date_from'];
        }
        
        if (!empty($params['date_to'])) {
            $conditions[] = 'generated_at <= :date_to';
            $bindings[':date_to'] = $params['date_to'];
        }
        
        if (!empty($params['period_start'])) {
            $conditions[] = 'period_start >= :period_start';
            $bindings[':period_start'] = $params['period_start'];
        }
        
        if (!empty($params['period_end'])) {
            $conditions[] = 'period_end <= :period_end';
            $bindings[':period_end'] = $params['period_end'];
        }
        
        if (!empty($params['search'])) {
            $conditions[] = '(title LIKE :search OR description LIKE :search OR parameters LIKE :search)';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        
        $whereClause = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM system_reports WHERE {$whereClause}
        ");
        foreach ($bindings as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
        
        $query = "
            SELECT id, report_type, title, description, format, status,
                   parameters, period_start, period_end, file_path,
                   generated_by, generated_at
            FROM system_reports
            WHERE {$whereClause}
            ORDER BY {$sortBy} {$sortDir}
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->db->prepare($query);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logger->info('System reports fetched', [
            'user' => $this->currentUser->getId(),
            'filters' => $params,
            'total' => $totalRecords,
            'page' => $page,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ]);
        
        return [
            'data' => $records,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $totalRecords,
                'total_pages' => (int)ceil($totalRecords / $perPage),
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
            ]
        ];
    }

    public function createReport(array $reportData): int
    {
        $sql = "INSERT INTO system_reports (report_type, title, description, format,
                                           status, parameters, period_start, period_end,
                                           generated_by, generated_at)
                VALUES (:report_type, :title, :description, :format,
                        :status, :parameters, :period_start, :period_end,
                        :generated_by, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':report_type', $reportData['report_type']);
        $stmt->bindValue(':title', $reportData['title']);
        $stmt->bindValue(':description', $reportData['description'] ?? null);
        $stmt->bindValue(':format', $reportData['format']);
        $stmt->bindValue(':status', $reportData['status'] ?? 'pending');
        $stmt->bindValue(':parameters', json_encode($reportData['parameters'] ?? []));
        $stmt->bindValue(':period_start', $reportData['period_start'] ?? null);
        $stmt->bindValue(':period_end', $reportData['period_end'] ?? null);
        $stmt->bindValue(':generated_by', $this->currentUser->getId(), PDO::PARAM_INT);
        
        $stmt->execute();
        
        return (int)$this->db->lastInsertId();
    }
}
