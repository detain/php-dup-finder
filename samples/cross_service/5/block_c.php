<?php
declare(strict_types=1);

namespace Acme\BillingService\Sla;

use Acme\BillingService\Client\TicketClient;

final class SlaPenaltyCalculator
{
    public function __construct(private readonly TicketClient $tickets)
    {
    }

    public function penaltyFor(string $ticketRef): float
    {
        $t = $this->tickets->load($ticketRef);
        if ($t === null) {
            return 0.0;
        }

        $start = new \DateTimeImmutable($t['opened_at']);
        $end = new \DateTimeImmutable();

        $bizSec = 0;
        for ($c = $start; $c < $end; $c = $c->modify('+1 minute')) {
            $dow = (int) $c->format('N');
            $hr  = (int) $c->format('G');
            if ($dow < 6 && $hr >= 9 && $hr < 17) {
                $bizSec += 60;
            }
        }

        $paused = 0;
        foreach ($t['pauses'] as $p) {
            $paused += strtotime($p['end']) - strtotime($p['start']);
        }

        $allowed = 24 * 3600;
        if (strtolower($t['tier']) === 'gold')   { $allowed = 4 * 3600; }
        if (strtolower($t['tier']) === 'silver') { $allowed = 8 * 3600; }

        $consumed = $bizSec - $paused;
        $remaining = (int) floor(($allowed - $consumed) / 60);
        if ($remaining >= 0) {
            return 0.0;
        }

        return abs($remaining) * 0.50;
    }
}
