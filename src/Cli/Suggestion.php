<?php
declare(strict_types=1);

namespace Phpdup\Cli;

/**
 * The auto-tuner's verdict for a corpus.
 *
 * Carries the picked profile bucket (tiny/small/medium/large), the
 * counts that drove the decision, the resulting Config-shaped override
 * dictionary, and a short human rationale string the CLI can echo so
 * the user understands why their thresholds shifted.
 */
final class Suggestion
{
    /**
     * @param array<string,mixed> $overrides Keys mirror the names accepted by
     *                                       {@see ConfigLoader::load()}'s overrides
     *                                       parameter; one extra synthetic key
     *                                       'exact_only' is consumed by the CLI to
     *                                       force --exact-only on huge corpora.
     */
    public function __construct(
        public readonly string $profile,
        public readonly int $files,
        public readonly int $bytes,
        public readonly array $overrides,
        public readonly string $rationale,
    ) {
    }
}
