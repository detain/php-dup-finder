<?php

declare(strict_types=1);

namespace Acme\Support\Triage;

use Acme\Support\Triage\Dto\TicketSnapshot;
use Acme\Support\Triage\Dto\PrioritizedTicket;

final class TicketPrioritizer
{
    /**
     * @param TicketSnapshot[] $tickets
     * @return PrioritizedTicket[]
     */
    public function prioritize(array $tickets): array
    {
        $weights = [
            'customer_tier'    => 0.40,
            'sla_breach_risk'  => 0.25,
            'sentiment'        => 0.15,
            'age_hours'        => 0.10,
            'reply_count'      => 0.10,
        ];

        $prioritized = [];
        foreach ($tickets as $ticket) {
            $features = [
                'customer_tier'   => $ticket->tierScore,
                'sla_breach_risk' => min($ticket->slaRiskRatio, 1.0),
                'sentiment'       => 1.0 - $ticket->sentimentScore,
                'age_hours'       => min($ticket->ageHours / 48.0, 1.0),
                'reply_count'     => min($ticket->replyCount / 8.0, 1.0),
            ];

            $score = 0.0;
            foreach ($features as $name => $value) {
                $score += $value * ($weights[$name] ?? 0.0);
            }

            $prioritized[] = new PrioritizedTicket($ticket->id, round($score, 4), $features);
        }

        usort(
            $prioritized,
            static fn(PrioritizedTicket $a, PrioritizedTicket $b): int => $b->score <=> $a->score,
        );

        return $prioritized;
    }
}
