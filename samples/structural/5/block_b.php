<?php

declare(strict_types=1);

namespace Acme\Payouts\Service;

use Acme\Locking\LockManager;
use Acme\Payouts\Repository\PayoutRepository;
use Acme\Payouts\Gateway\BankGateway;
use Psr\Log\LoggerInterface;

final class PayoutProcessor
{
    public function __construct(
        private readonly LockManager $locks,
        private readonly PayoutRepository $payouts,
        private readonly BankGateway $bank,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(int $payoutId): bool
    {
        $lockKey = "payout:{$payoutId}";
        $lock = $this->locks->acquire($lockKey, 600);
        if ($lock === null) {
            $this->logger->info('payout lock contended', ['payout_id' => $payoutId]);
            return false;
        }

        try {
            $payout = $this->payouts->find($payoutId);
            if ($payout === null || $payout->status() !== 'queued') {
                $this->logger->debug('payout not processable', ['id' => $payoutId, 'status' => $payout?->status()]);
                return false;
            }

            $this->payouts->updateStatus($payoutId, 'sending');
            $reference = $this->bank->send($payout->amountCents(), $payout->iban());
            $this->payouts->setReference($payoutId, $reference);
            $this->payouts->updateStatus($payoutId, 'sent');

            $this->logger->info('payout sent', ['id' => $payoutId, 'ref' => $reference]);
            return true;
        } finally {
            $this->locks->release($lock);
        }
    }
}
