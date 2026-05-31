<?php

declare(strict_types=1);

namespace Acme\Ledger\Service;

use Doctrine\DBAL\Connection;
use Acme\Ledger\Entity\JournalEntry;
use Psr\Log\LoggerInterface;

final class JournalPostingService
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function post(string $accountCode, int $amountCents, string $memo, int $actorId): JournalEntry
    {
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement(
                'UPDATE account_balance SET cents = cents + ? WHERE code = ?',
                [$amountCents, $accountCode],
            );

            $this->db->executeStatement(
                'INSERT INTO journal_entry (account_code, amount_cents, memo, posted_by, posted_at) VALUES (?, ?, ?, ?, NOW())',
                [$accountCode, $amountCents, $memo, $actorId],
            );

            $id = (int) $this->db->lastInsertId();

            $this->db->executeStatement(
                'INSERT INTO journal_entry_audit (entry_id, action, actor_id) VALUES (?, ?, ?)',
                [$id, 'post', $actorId],
            );

            $this->db->commit();
            $this->logger->info('journal posted', ['account' => $accountCode, 'cents' => $amountCents]);

            return new JournalEntry($id, $accountCode, $amountCents, $memo);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('journal post failed', ['error' => $e->getMessage(), 'account' => $accountCode]);
            throw $e;
        }
    }
}
