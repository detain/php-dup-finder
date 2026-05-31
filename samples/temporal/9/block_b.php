<?php
declare(strict_types=1);

namespace Travel\Booking\Hotel;

use Psr\Log\LoggerInterface;

final class HotelBookingWorkflow
{
    public function __construct(
        private RoomInventory $rooms,
        private PaymentGateway $payments,
        private ReservationStore $reservations,
        private LoggerInterface $log,
    ) {}

    public function book(int $userId, string $hotelId, string $roomType, int $nights, int $cents): string
    {
        $sagaId = 'htl-' . bin2hex(random_bytes(6));
        $compensations = [];
        try {
            $reservation = $this->rooms->reserve($hotelId, $roomType, $nights, $userId);
            $compensations[] = fn() => $this->rooms->cancelReservation($reservation);

            $charge = $this->payments->charge($userId, $cents, "hotel {$hotelId}");
            $compensations[] = fn() => $this->payments->refund($charge);

            $confirmation = $this->reservations->confirm($reservation, $charge);
            $compensations[] = fn() => $this->reservations->cancelConfirmation($confirmation);

            $this->log->info('hotel.saga.confirmed', ['saga' => $sagaId]);
            return $confirmation;
        } catch (\Throwable $e) {
            foreach (array_reverse($compensations) as $undo) {
                try { $undo(); } catch (\Throwable $c) {
                    $this->log->error('hotel.saga.compensation_failed', ['err' => $c->getMessage()]);
                }
            }
            $this->log->error('hotel.saga.rolled_back', ['saga' => $sagaId, 'err' => $e->getMessage()]);
            throw $e;
        }
    }
}
