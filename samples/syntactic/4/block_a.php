<?php
declare(strict_types=1);

namespace Acme\Reporting;

final class CustomerLedgerAggregator
{
    public function __construct(private PdoConnection $db) {}

    public function aggregate(string $accountId): LedgerSummary
    {
        $accumulator = [
            'debits'  => 0,
            'credits' => 0,
            'rows'    => 0,
        ];
        $cursor = $this->db->createCursor(
            'SELECT amount, direction FROM ledger WHERE account_id = ? ORDER BY id',
            [$accountId],
            500,
        );

        while ($cursor->hasMore()) {
            $page = $cursor->next();
            foreach ($page as $row) {
                if ($row['direction'] === 'debit') {
                    $accumulator['debits'] += (int) $row['amount'];
                } else {
                    $accumulator['credits'] += (int) $row['amount'];
                }
                $accumulator['rows']++;
            }
        }

        $net = $accumulator['credits'] - $accumulator['debits'];

        return new LedgerSummary(
            accountId: $accountId,
            debits:    $accumulator['debits'],
            credits:   $accumulator['credits'],
            net:       $net,
            count:     $accumulator['rows'],
        );
    }
}
