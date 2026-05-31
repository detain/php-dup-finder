<?php
declare(strict_types=1);

namespace Acme\Billing;

use PDO;
use Psr\Log\LoggerInterface;

final class BillingService
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

    public function chargeInvoice(int $invoiceId, int $cents): void
    {
        $this->db->beginTransaction();
        try {
            $this->log->info('billing.charge.start', ['invoice' => $invoiceId, 'amount' => $cents]);
            $stmt = $this->db->prepare(
                'UPDATE invoices SET paid_cents = paid_cents + :c, paid_at = NOW() WHERE id = :id'
            );
            $stmt->execute(['c' => $cents, 'id' => $invoiceId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->log->error('billing.charge.fail', ['error' => $e->getMessage()]);

            throw $e;
        }
    }
}
