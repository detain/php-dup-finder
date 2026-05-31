<?php
declare(strict_types=1);

namespace Acme\Audit;

use PDO;
use Psr\Log\LoggerInterface;

final class AuditService
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

    public function record(string $actor, string $action, array $payload): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_log (actor, action, payload_json, created_at)
                  VALUES (:a, :ac, :p, NOW())'
        );
        $stmt->execute([
            'a'  => $actor,
            'ac' => $action,
            'p'  => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $this->log->info('audit.record', ['actor' => $actor, 'action' => $action]);
    }
}
