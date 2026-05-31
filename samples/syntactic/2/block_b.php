<?php
declare(strict_types=1);

namespace Acme\Payments;

final class PaymentEventRouter
{
    public function __construct(private EventBus $bus) {}

    public function route(string $eventName, string $merchantId): RoutedEvent
    {
        $bucket = match (true) {
            str_starts_with($eventName, 'charge.refund')     => 'reversal',
            str_starts_with($eventName, 'charge.dispute')    => 'dispute',
            str_starts_with($eventName, 'charge.failed')     => 'failure',
            str_starts_with($eventName, 'charge.succeeded')  => 'capture',
            str_starts_with($eventName, 'charge.pending')    => 'pending',
            str_starts_with($eventName, 'payout.')           => 'payout',
            default                                          => 'unclassified',
        };

        $urgent = match ($bucket) {
            'dispute', 'reversal' => true,
            'failure', 'pending'  => false,
            default               => false,
        };

        $this->bus->publish('payments.routed.' . $bucket, ['merchant' => $merchantId]);

        return new RoutedEvent(
            event:    $eventName,
            bucket:   $bucket,
            urgent:   $urgent,
            merchant: $merchantId,
        );
    }
}
