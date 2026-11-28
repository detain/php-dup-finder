<?php
declare(strict_types=1);

namespace Phpdup\Text;

/**
 * Item 7.1: one token of the loose non-PHP lex.
 *
 * Value object over the raw source: {@see $text} is the verbatim slice,
 * {@see blanked()} is the comparison alphabet — identifiers, numbers and
 * string literals collapse to single letters ('I'/'N'/'S') so a renamed or
 * re-literalled twin (type-2 by definition) still matches; keywords,
 * punctuation and operators ride through unchanged (they are the skeleton).
 * Offsets are 0-based byte offsets into the file; {@see $line} is the
 * 1-based line the token starts on, which is what mints report ranges.
 */
final class TextToken
{
    public const KIND_IDENT   = 'ident';
    public const KIND_NUMBER  = 'number';
    public const KIND_STRING  = 'string';
    public const KIND_PUNCT   = 'punct';
    public const KIND_KEYWORD = 'keyword';

    public function __construct(
        public readonly string $kind,
        public readonly string $text,
        public readonly int $line,
        public readonly int $start,
        public readonly int $end,
        public readonly string $blanked,
    ) {
    }
}
