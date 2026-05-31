<?php
declare(strict_types=1);

namespace Travel\Booking;

use Psr\Log\LoggerInterface;

final class SagaRunner
{
    /** @var list<callable():void> */
    private array $compensations = [];

    public function __construct(private LoggerInterface $log) {}

    /**
     * @template T
     * @param callable(self):T $steps
     * @return T
     */
    public function run(string $sagaId, callable $steps)
    {
        $this->compensations = [];
        try {
            $result = $steps($this);
            $this->log->info('saga.confirmed', ['saga' => $sagaId]);
            return $result;
        } catch (\Throwable $e) {
            foreach (array_reverse($this->compensations) as $undo) {
                try { $undo(); } catch (\Throwable $c) {
                    $this->log->error('saga.compensation_failed', ['saga' => $sagaId, 'err' => $c->getMessage()]);
                }
            }
            $this->log->error('saga.rolled_back', ['saga' => $sagaId, 'err' => $e->getMessage()]);
            throw $e;
        }
    }

    public function onUndo(callable $undo): void
    {
        $this->compensations[] = $undo;
    }
}

final class FlightBookingWorkflow
{
    public function __construct(
        private SagaRunner $saga,
        private FlightInventory $inventory,
        private PaymentGateway $payments,
        private TicketIssuer $tickets,
    ) {}

    public function book(int $userId, string $flightId, int $cents): string
    {
        return $this->saga->run('flt-' . bin2hex(random_bytes(6)), function (SagaRunner $s) use ($userId, $flightId, $cents): string {
            $hold = $this->inventory->hold($flightId, $userId);
            $s->onUndo(fn() => $this->inventory->release($hold));
            $charge = $this->payments->charge($userId, $cents, "flight {$flightId}");
            $s->onUndo(fn() => $this->payments->refund($charge));
            $ticket = $this->tickets->issue($userId, $flightId, $charge);
            $s->onUndo(fn() => $this->tickets->void($ticket));
            return $ticket;
        });
    }
}
