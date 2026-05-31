<?php

declare(strict_types=1);

namespace Acme\Risk\Streaming;

use Acme\Risk\Model\Transaction;
use Acme\Risk\Adapter\KafkaConsumer;
use Acme\Risk\Repository\AlertRepository;

final class SuspiciousTransactionConsumer
{
    public function __construct(
        private KafkaConsumer $consumer,
        private AlertRepository $alerts,
    ) {
    }

    public function run(): void
    {
        foreach ($this->consumer->consume('payments.events') as $event) {
            $tx = Transaction::fromEvent($event);

            if ($tx->isSuspicious()) {
                $this->alerts->raise($tx->id(), 'manual_review');
                continue;
            }

            $this->consumer->ack($event);
        }
    }
}
