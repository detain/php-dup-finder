<?php
declare(strict_types=1);

namespace Shop\Db\Migrations;

final class AuditTableDdl
{
    public static function createSql(string $tableName): string
    {
        return <<<SQL
        CREATE TABLE IF NOT EXISTS {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            actor_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(64) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            payload JSON NOT NULL,
            ip_addr VARBINARY(16) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT fk_{$tableName}_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            INDEX idx_{$tableName}_target (target_id, action),
            INDEX idx_{$tableName}_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL;
    }
}

final class M20240101AddOrderAudit
{
    public function __construct(private \PDO $pdo) {}

    public function up(): void
    {
        $this->pdo->exec(AuditTableDdl::createSql('order_audit_log'));
    }

    public function down(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS order_audit_log');
    }
}
