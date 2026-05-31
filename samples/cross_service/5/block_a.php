<?php
declare(strict_types=1);

namespace Acme\TicketingService\Sla;

use Acme\TicketingService\Repository\TicketRepository;

final class TicketSlaTimer
{
    public function __construct(private readonly TicketRepository $tickets)
    {
    }

    public function minutesRemaining(string $ticketId): int
    {
        $ticket = $this->tickets->find($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('ticket not found');
        }

        $opened = new \DateTimeImmutable($ticket->openedAt);
        $now = new \DateTimeImmutable();

        $businessSeconds = 0;
        $cursor = $opened;
        while ($cursor < $now) {
            $dow = (int) $cursor->format('N');
            $hour = (int) $cursor->format('G');
            if ($dow < 6 && $hour >= 9 && $hour < 17) {
                $businessSeconds += 60;
            }
            $cursor = $cursor->modify('+1 minute');
        }

        $pauseSeconds = 0;
        foreach ($ticket->pauseWindows as $pw) {
            $pauseSeconds += (new \DateTimeImmutable($pw->end))->getTimestamp()
                - (new \DateTimeImmutable($pw->start))->getTimestamp();
        }

        $deadlines = ['gold' => 4 * 3600, 'silver' => 8 * 3600, 'bronze' => 24 * 3600];
        $allowed = $deadlines[strtolower($ticket->tier)] ?? 24 * 3600;

        $elapsed = $businessSeconds - $pauseSeconds;
        return (int) floor(($allowed - $elapsed) / 60);
    }
}
