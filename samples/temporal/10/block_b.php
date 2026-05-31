<?php
declare(strict_types=1);

namespace ECommerce\Events\Payments;

use ECommerce\Outbox\OutboxRepository;
use ECommerce\Messaging\Publisher;
use Psr\Log\LoggerInterface;

final class PaymentCapturedEmitter
{
    public function __construct(
        private OutboxRepository $outbox,
        private Publisher $publisher,
        private LoggerInterface $log,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function emit(string $paymentId, array $payload): string
    {
        $eventId = bin2hex(random_bytes(16));
        $this->outbox->insert([
            'event_id'    => $eventId,
            'aggregate'   => 'payment',
            'aggregate_id'=> $paymentId,
            'type'        => 'payment.captured',
            'payload'     => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'  => date(DATE_ATOM),
            'published_at'=> null,
        ]);

        try {
            $this->publisher->publish('payment.captured', [
                'event_id'   => $eventId,
                'payment_id' => $paymentId,
                'data'       => $payload,
            ]);
        } catch (\Throwable $e) {
            $this->log->warning('payment.captured.publish_failed', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);
            return $eventId;
        }

        $this->outbox->markPublished($eventId, date(DATE_ATOM));
        $this->log->info('payment.captured.published', ['event_id' => $eventId]);
        return $eventId;
    }
}
