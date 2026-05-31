<?php
declare(strict_types=1);

namespace App\Billing\Ledger;

use App\Database\Connection;

final class LedgerWriter
{
    public function __construct(private Connection $db) {}

    public function recordEntry(int $accountId, int $amountCents, string $type, string $reference): int
    {
        if ($amountCents === 0) {
            throw new \InvalidArgumentException('Cannot record zero-value ledger entry');
        }

        $account = $this->db->fetchOne(
            'SELECT id, balance_cents, is_closed FROM accounts WHERE id = ? FOR UPDATE',
            [$accountId]
        );

        if ($account === null) {
            throw new \RuntimeException('Account not found: ' . $accountId);
        }

        if ((bool)$account['is_closed'] === true) {
            throw new \DomainException('Cannot post to closed account');
        }

        $newBalance = (int)$account['balance_cents'] + $amountCents;

        if ($newBalance < 0 && $type !== 'overdraft') {
            throw new \DomainException('Insufficient funds');
        }

        $this->db->execute(
            'INSERT INTO ledger_entries (account_id, amount_cents, currency, entry_type, reference, balance_after_cents, posted_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$accountId, $amountCents, 'USD', $type, $reference, $newBalance]
        );

        $this->db->execute(
            'UPDATE accounts SET balance_cents = ?, updated_at = NOW() WHERE id = ?',
            [$newBalance, $accountId]
        );

        return $this->db->lastInsertId();
    }
}
