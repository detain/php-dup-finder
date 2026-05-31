<?php
declare(strict_types=1);

namespace Travel\Booking\Event;

use Psr\Log\LoggerInterface;

final class EventTicketWorkflow
{
    public function __construct(
        private SeatingService $seating,
        private PaymentGateway $payments,
        private EventTicketIssuer $tickets,
        private LoggerInterface $log,
    ) {}

    public function book(int $userId, string $eventId, string $seatId, int $cents): string
    {
        $sagaId = 'evt-' . bin2hex(random_bytes(6));
        $compensations = [];
        try {
            $hold = $this->seating->holdSeat($eventId, $seatId, $userId);
            $compensations[] = fn() => $this->seating->releaseSeat($hold);

            $charge = $this->payments->charge($userId, $cents, "event {$eventId}");
            $compensations[] = fn() => $this->payments->refund($charge);

            $ticket = $this->tickets->emit($userId, $eventId, $seatId, $charge);
            $compensations[] = fn() => $this->tickets->cancel($ticket);

            $this->log->info('event.saga.confirmed', ['saga' => $sagaId]);
            return $ticket;
        } catch (\Throwable $e) {
            foreach (array_reverse($compensations) as $undo) {
                try { $undo(); } catch (\Throwable $c) {
                    $this->log->error('event.saga.compensation_failed', ['err' => $c->getMessage()]);
                }
            }
            $this->log->error('event.saga.rolled_back', ['saga' => $sagaId, 'err' => $e->getMessage()]);
            throw $e;
        }
    }
}
