<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use Acme\Shared\Model\Transaction;

final class TransactionReviewPolicy
{
    /** @param list<string> $domesticCountries */
    public function __construct(
        private int $largeAmountCents = 100_000,
        private array $domesticCountries = ['US', 'CA'],
        private int $minDeviceTrustScore = 50,
    ) {
    }

    public function needsManualReview(Transaction $tx): bool
    {
        if ($tx->amountCents() >= $this->largeAmountCents) {
            return true;
        }

        if (!in_array($tx->cardCountryCode(), $this->domesticCountries, true)) {
            return true;
        }

        if ($tx->deviceTrustScore() < $this->minDeviceTrustScore) {
            return true;
        }

        return false;
    }
}

final class TransactionProcessor
{
    public function __construct(private TransactionReviewPolicy $policy) {}

    public function process(Transaction $tx): string
    {
        return $this->policy->needsManualReview($tx) ? 'queued' : 'auto_approved';
    }
}
