<?php
declare(strict_types=1);

namespace Phpdup\Text;

/**
 * Item 7.1: one tokenized non-PHP file — the harvester's unit of work.
 *
 * Holds the token list plus the two projections the window matcher needs:
 * the blanked alphabet stream ({@see $blanked}) and the 1-based start line
 * per token ({@see $lines}), so a harvested run can be reported with real
 * file ranges without re-walking tokens.
 */
final class TextFileTokens
{
    /** @param list<TextToken> $tokens */
    public function __construct(
        public readonly string $file,
        public readonly LangProfile $profile,
        public readonly array $tokens,
    ) {
    }

    /** @var list<string>|null blanked stream, minted on first need */
    private ?array $blankedStream = null;

    /** @var list<int>|null start line per token, minted on first need */
    private ?array $tokenLines = null;

    /**
     * @return list<string> the blanked token stream (tier comparison alphabet)
     */
    public function blanked(): array
    {
        if ($this->blankedStream === null) {
            $stream  = [];
            foreach ($this->tokens as $t) {
                $stream[] = $t->blanked;
            }
            $this->blankedStream = $stream;
        }
        return $this->blankedStream;
    }

    /**
     * @return list<int> 1-based line of each token, parallel to {@see blanked()}
     */
    public function lines(): array
    {
        if ($this->tokenLines === null) {
            $lines = [];
            foreach ($this->tokens as $t) {
                $lines[] = $t->line;
            }
            $this->tokenLines = $lines;
        }
        return $this->tokenLines;
    }

    public function size(): int
    {
        return count($this->tokens);
    }
}
