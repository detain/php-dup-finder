<?php
declare(strict_types=1);

namespace Acme\MonitoringService\Sla;

use Acme\MonitoringService\Source\TicketFeed;

final class SlaBreachDetector
{
    public function __construct(private readonly TicketFeed $feed)
    {
    }

    public function evaluate(string $tid): array
    {
        $tk = $this->feed->byId($tid);
        if (!$tk) {
            return ['state' => 'unknown'];
        }

        $open = new \DateTimeImmutable($tk['opened_at']);
        $now = new \DateTimeImmutable();
        $work = 0;
        $ptr = $open;
        while ($ptr < $now) {
            $d = (int) $ptr->format('N');
            $h = (int) $ptr->format('G');
            if ($d < 6 && $h >= 9 && $h < 17) {
                $work += 60;
            }
            $ptr = $ptr->modify('+1 minute');
        }

        $paused = 0;
        foreach ($tk['pauses'] as $pw) {
            $paused += strtotime($pw['end']) - strtotime($pw['start']);
        }

        $allowed = match (strtolower($tk['tier'])) {
            'gold'   => 4 * 3600,
            'silver' => 8 * 3600,
            default  => 24 * 3600,
        };

        $consumed = $work - $paused;
        $remaining = (int) floor(($allowed - $consumed) / 60);
        return [
            'state' => $remaining < 0 ? 'breached' : 'on_track',
            'minutes_remaining' => $remaining,
        ];
    }
}
