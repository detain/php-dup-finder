<?php

declare(strict_types=1);

namespace Acme\Fraud\Queue;

use Acme\Fraud\Model\Customer;
use Acme\Fraud\Repository\CustomerRepository;
use Acme\Fraud\Queue\ReviewQueue;
use Psr\Log\LoggerInterface;

final class FraudScreeningWorker
{
    public function __construct(
        private CustomerRepository $customers,
        private ReviewQueue $queue,
        private LoggerInterface $log,
    ) {
    }

    public function screen(int $customerId): void
    {
        $customer = $this->customers->find($customerId);

        $score = $customer->fraudScore();
        $chargebacks = $customer->chargebackCount(days: 90);
        $velocity = $customer->purchasesInLastHour();

        $isHighRisk = $score >= 80 || $chargebacks >= 2 || $velocity >= 5;

        if ($isHighRisk) {
            $this->queue->push($customer->id());
            $this->log->warning('Customer flagged high-risk', [
                'customer' => $customer->id(),
                'score' => $score,
            ]);
        }
    }
}
