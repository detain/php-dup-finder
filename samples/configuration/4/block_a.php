<?php
declare(strict_types=1);

namespace Acme\Reporting;

use PDO;
use Psr\Log\LoggerInterface;

final class ReportingService
{
    private PDO $db;

    public function __construct(private LoggerInterface $log)
    {
        $this->db = new PDO(
            'mysql:host=db-primary.internal;port=3306;dbname=acme;charset=utf8mb4',
            'acme_app',
            'acme_app_secret',
            [
                PDO::ATTR_PERSISTENT         => true,
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION transaction_isolation = 'REPEATABLE-READ'",
            ]
        );
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    public function revenueByDay(string $from, string $to): array
    {
        $this->log->info('reporting.revenue', ['from' => $from, 'to' => $to]);
        $stmt = $this->db->prepare(
            'SELECT DATE(created_at) d, SUM(total_cents) t
               FROM orders
              WHERE created_at BETWEEN :from AND :to
              GROUP BY DATE(created_at)
              ORDER BY d'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);

        return $stmt->fetchAll();
    }
}
