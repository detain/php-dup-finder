<?php
declare(strict_types=1);

namespace Travel\Booking\Flight;

use Psr\Log\LoggerInterface;

final class FlightBookingWorkflow
{
    public function __construct(
        private FlightInventory $inventory,
        private PaymentGateway $payments,
        private TicketIssuer $tickets,
        private LoggerInterface $log,
    ) {}

    public function book(int $userId, string $flightId, int $cents): string
    {
        $sagaId = 'flt-' . bin2hex(random_bytes(6));
        $compensations = [];
        try {
            $hold = $this->inventory->hold($flightId, $userId);
            $compensations[] = fn() => $this->inventory->release($hold);

            $charge = $this->payments->charge($userId, $cents, "flight {$flightId}");
            $compensations[] = fn() => $this->payments->refund($charge);

            $ticket = $this->tickets->issue($userId, $flightId, $charge);
            $compensations[] = fn() => $this->tickets->void($ticket);

            $this->log->info('flight.saga.confirmed', ['saga' => $sagaId]);
            return $ticket;
        } catch (\Throwable $e) {
            foreach (array_reverse($compensations) as $undo) {
                try { $undo(); } catch (\Throwable $c) {
                    $this->log->error('flight.saga.compensation_failed', ['err' => $c->getMessage()]);
                }
            }
            $this->log->error('flight.saga.rolled_back', ['saga' => $sagaId, 'err' => $e->getMessage()]);
            throw $e;
        }
    }
}
