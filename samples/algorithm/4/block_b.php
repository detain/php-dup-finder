<?php

declare(strict_types=1);

namespace Acme\Fraud\Velocity;

use Acme\Fraud\Velocity\Dto\AttemptEvent;

final class LoginAttemptCounter
{
    /** @var list<AttemptEvent> */
    private array $events = [];

    public function __construct(private readonly int $windowSeconds = 300)
    {
    }

    public function note(string $ipAddress, int $timestamp): void
    {
        $this->events[] = new AttemptEvent($timestamp, $ipAddress);

        $cutoff = $timestamp - $this->windowSeconds;
        while ($this->events !== [] && $this->events[0]->timestamp < $cutoff) {
            array_shift($this->events);
        }
    }

    public function attemptsFrom(string $ipAddress, int $now): int
    {
        $cutoff = $now - $this->windowSeconds;
        $count = 0;
        foreach ($this->events as $event) {
            if ($event->timestamp < $cutoff) {
                continue;
            }
            if ($event->ipAddress === $ipAddress) {
                $count++;
            }
        }

        return $count;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
