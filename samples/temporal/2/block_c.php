<?php
declare(strict_types=1);

namespace Banking\Ledger\Fees;

use PDO;
use Psr\Log\LoggerInterface;

final class MonthlyFeeService
{
    public function __construct(private PDO $pdo, private LoggerInterface $log) {}

    public function charge(int $accountId, int $cents, string $code): string
    {
        $reference = 'FE-' . bin2hex(random_bytes(6));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT balance_cents, fee_waived FROM accounts WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \DomainException('account_missing');
            }
            if ((bool) $row['fee_waived']) {
                $this->pdo->commit();
                return 'WAIVED';
            }
            $this->pdo->prepare('UPDATE accounts SET balance_cents = balance_cents - :c WHERE id = :id')
                ->execute(['c' => $cents, 'id' => $accountId]);
            $this->pdo->prepare('INSERT INTO fees (ref, account_id, cents, code, charged_at) VALUES (?,?,?,?,NOW())')
                ->execute([$reference, $accountId, $cents, $code]);
            $this->pdo->prepare('INSERT INTO ledger (ref, src, dst, cents, memo, created_at) VALUES (?,?,?,?,?,NOW())')
                ->execute([$reference, $accountId, 0, $cents, "fee {$code}"]);
            $this->pdo->commit();
            $this->log->info('fee.charge.ok', ['ref' => $reference]);
            return $reference;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->log->error('fee.charge.fail', ['err' => $e->getMessage()]);
            throw $e;
        }
    }
}
