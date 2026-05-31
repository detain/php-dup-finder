<?php

declare(strict_types=1);

namespace Acme\Common\Window;

final class RollingWindow
{
    /** @var list<array{ts:int, value:mixed}> */
    private array $entries = [];

    /**
     * @param callable(list<mixed>):mixed $reducer
     */
    public function __construct(
        private readonly int $windowSeconds,
        private $reducer,
    ) {
    }

    public function push(mixed $value, int $timestamp): void
    {
        $this->entries[] = ['ts' => $timestamp, 'value' => $value];
        $this->prune($timestamp);
    }

    public function aggregate(int $now): mixed
    {
        $this->prune($now);
        $values = array_column($this->entries, 'value');

        return ($this->reducer)($values);
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    private function prune(int $now): void
    {
        $cutoff = $now - $this->windowSeconds;
        while ($this->entries !== [] && $this->entries[0]['ts'] < $cutoff) {
            array_shift($this->entries);
        }
    }
}
