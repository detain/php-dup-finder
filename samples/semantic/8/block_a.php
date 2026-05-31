<?php

declare(strict_types=1);

namespace Acme\Payments\Processing;

use Acme\Payments\Model\Transaction;
use Acme\Payments\Repository\ReviewQueueRepository;
use Acme\Payments\Logger\AuditLogger;

final class TransactionProcessor
{
    public function __construct(
        private ReviewQueueRepository $queue,
        private AuditLogger $audit,
    ) {
    }

    public function process(Transaction $tx): string
    {
        $amountCents = $tx->amountCents();
        $country = $tx->card()->countryCode();
        $deviceScore = $tx->deviceTrustScore();

        $needsReview = $amountCents >= 100000
            || !in_array($country, ['US', 'CA'], true)
            || $deviceScore < 50;

        if ($needsReview) {
            $this->queue->enqueue($tx->id());
            $this->audit->log('manual_review', ['tx' => $tx->id()]);
            return 'queued';
        }

        return 'auto_approved';
    }
}
