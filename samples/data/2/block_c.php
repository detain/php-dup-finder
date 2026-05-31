<?php
declare(strict_types=1);

namespace App\Billing\Statements;

use App\Database\Connection;
use App\Pdf\StatementPdfBuilder;

final class MonthlyStatementService
{
    public function __construct(
        private Connection $db,
        private StatementPdfBuilder $pdf,
    ) {
    }

    public function build(int $customerId, string $periodStart, string $periodEnd): string
    {
        $customer = $this->db->fetchOne(
            'SELECT id, name, email, billing_address FROM customers WHERE id = ?',
            [$customerId]
        );

        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        $entries = $this->db->fetchAll(
            'SELECT amount_cents, entry_type, reference, posted_at
             FROM ledger_entries
             WHERE account_id IN (SELECT id FROM accounts WHERE customer_id = ?)
               AND posted_at BETWEEN ? AND ?
             ORDER BY posted_at ASC',
            [$customerId, $periodStart, $periodEnd]
        );

        $totalCharges = 0;
        $totalPayments = 0;
        foreach ($entries as $entry) {
            $amt = (int)$entry['amount_cents'];
            if ($amt < 0) {
                $totalCharges += abs($amt);
            } else {
                $totalPayments += $amt;
            }
        }

        return $this->pdf->render([
            'customer'       => $customer,
            'period_start'   => $periodStart,
            'period_end'     => $periodEnd,
            'entries'        => $entries,
            'total_charges'  => $totalCharges,
            'total_payments' => $totalPayments,
            'currency'       => 'USD',
            'footer_note'    => 'All amounts shown in USD.',
        ]);
    }
}
