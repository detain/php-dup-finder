<?php

declare(strict_types=1);

namespace App\Support\Tickets;

use App\Domain\Ticket;
use App\Repositories\TicketRepository;
use DateTimeImmutable;
use DateTimeZone;

final class TicketPrioritizer
{
    public function __construct(private TicketRepository $tickets) {}

    public function prioritize(Ticket $ticket): void
    {
        $created = $ticket->createdAt->setTimezone(new DateTimeZone('UTC'));
        $dow = (int) $created->format('N'); // 1=Mon ... 7=Sun
        $hour = (int) $created->format('G');

        $insideHours = $dow >= 1 && $dow <= 5 && $hour >= 9 && $hour < 17;

        if ($ticket->isUrgentTopic()) {
            $ticket->priority = 'P1';
            $ticket->slaDueAt = $created->modify('+1 hour');
        } elseif (!$insideHours) {
            // Outside Mon-Fri 09-17 UTC: queue but downrank reasonably.
            $ticket->priority = 'P3';
            $ticket->slaDueAt = $this->nextBusinessHourStart($created)->modify('+4 hours');
        } else {
            $ticket->priority = 'P2';
            $ticket->slaDueAt = $created->modify('+4 hours');
        }

        $this->tickets->save($ticket);
    }

    private function nextBusinessHourStart(DateTimeImmutable $from): DateTimeImmutable
    {
        $cursor = $from;
        for ($i = 0; $i < 96; $i++) {
            $dow = (int) $cursor->format('N');
            $hour = (int) $cursor->format('G');
            if ($dow >= 1 && $dow <= 5 && $hour >= 9 && $hour < 17) {
                return $cursor->setTime($hour, 0);
            }
            $cursor = $cursor->modify('+1 hour');
        }
        return $cursor;
    }
}
