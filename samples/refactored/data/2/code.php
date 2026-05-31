<?php
declare(strict_types=1);

namespace App\Billing;

enum Currency: string
{
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';

    public function default(): self
    {
        return self::USD;
    }
}

namespace App\Billing\Charges;

use App\Billing\Currency;
use App\Payments\StripeClient;

final class SubscriptionChargeService
{
    public function __construct(private StripeClient $stripe) {}

    public function charge(int $amountCents, string $customerId, string $pm): array
    {
        return $this->stripe->createCharge([
            'amount'         => $amountCents,
            'currency'       => Currency::USD->value,
            'customer'       => $customerId,
            'payment_method' => $pm,
        ]);
    }
}

namespace App\Billing\Ledger;

use App\Billing\Currency;
use App\Database\Connection;

final class LedgerWriter
{
    public function __construct(private Connection $db) {}

    public function recordEntry(int $accountId, int $amountCents, string $type): int
    {
        $this->db->execute(
            'INSERT INTO ledger_entries (account_id, amount_cents, currency, entry_type) VALUES (?, ?, ?, ?)',
            [$accountId, $amountCents, Currency::USD->value, $type]
        );
        return $this->db->lastInsertId();
    }
}

namespace App\Billing\Statements;

use App\Billing\Currency;

final class MonthlyStatementService
{
    public function buildContext(array $data): array
    {
        return $data + [
            'currency'    => Currency::USD->value,
            'footer_note' => 'All amounts shown in ' . Currency::USD->value . '.',
        ];
    }
}
