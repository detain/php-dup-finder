<?php
declare(strict_types=1);

namespace Acme\Common\Sla;

/**
 * Shared SLA-timer contract (acme/sla-timer) consumed by ticketing, monitoring,
 * and billing. Each service supplies a SlaTicket; the policy returns canonical
 * "minutes remaining" so dashboards, alerts, and penalties agree.
 */
final class SlaTimerPolicy
{
    /** @var array<string,int> seconds */
    public const TIER_DEADLINES = [
        'gold'   => 14400,
        'silver' => 28800,
        'bronze' => 86400,
    ];

    public function __construct(private readonly BusinessClock $clock)
    {
    }

    public function minutesRemaining(SlaTicket $ticket, \DateTimeImmutable $now): int
    {
        $businessSeconds = $this->clock->businessSecondsBetween($ticket->openedAt, $now);

        $pausedSeconds = 0;
        foreach ($ticket->pauses as $window) {
            $pausedSeconds += $window->durationSeconds();
        }

        $allowed = self::TIER_DEADLINES[strtolower($ticket->tier)]
            ?? self::TIER_DEADLINES['bronze'];

        $consumed = max(0, $businessSeconds - $pausedSeconds);
        return (int) floor(($allowed - $consumed) / 60);
    }
}
