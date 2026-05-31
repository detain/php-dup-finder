<?php
declare(strict_types=1);

namespace Shop\Db\Migrations;

final class M20240310AddCustomerAudit
{
    public function __construct(private \PDO $pdo) {}

    public function up(): void
    {
        $this->pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS customer_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            actor_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(64) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            payload JSON NOT NULL,
            ip_addr VARBINARY(16) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            INDEX idx_audit_target (target_id, action),
            INDEX idx_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    public function down(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS customer_audit_log');
    }
}
