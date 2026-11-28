<?php
declare(strict_types=1);

namespace Phpdup\Text;

/**
 * Item 7.1: one maximal token run harvested inside a single file.
 *
 * [start, end) is the half-open token index range into
 * {@see $source}'s blanked stream; the line pair is the report range of
 * exactly those tokens. This is the tier's "Type-1/2 clone candidate" —
 * by construction its blanked stream is shared with at least one other
 * file's run and extended to the maximal position where the two streams
 * agree.
 */
final class TextRun
{
    public function __construct(
        public readonly TextFileTokens $source,
        public readonly int $start,
        public readonly int $end,
        public readonly int $startLine,
        public readonly int $endLine,
    ) {
    }

    public function file(): string
    {
        return $this->source->file;
    }

    public function size(): int
    {
        return $this->end - $this->start;
    }

    /** @return list<string> the blanked tokens of the run (fold payload) */
    public function blankedTokens(): array
    {
        return array_slice($this->source->blanked(), $this->start, $this->end - $this->start);
    }
}
