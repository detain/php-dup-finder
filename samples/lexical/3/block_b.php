<?php
declare(strict_types=1);

namespace Acme\Support\Reporting;

use Acme\Support\Domain\Ticket;
use Acme\Support\Domain\Priority;

final class TicketPriorityReporter
{
    public function priorityLabel(Ticket $ticket): string
    {
        $priority = $ticket->priority();

        // same token-shape: switch + return literal per case + default
        switch ($priority) {
            case Priority::Trivial:
                return 'cosmetic issue';
            case Priority::Low:
                return 'minor inconvenience';
            case Priority::Normal:
                return 'standard request';
            case Priority::High:
                return 'urgent attention';
            case Priority::Critical:
                return 'production blocker';
            default:
                return 'unclassified ticket';
        }
    }

    public function rosterRow(Ticket $ticket): string
    {
        return sprintf(
            'TKT-%s by %s — %s',
            $ticket->id(),
            $ticket->reporter(),
            $this->priorityLabel($ticket),
        );
    }
}
