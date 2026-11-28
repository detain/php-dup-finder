<?php
declare(strict_types=1);

namespace Phpdup\Text;

/**
 * Item 7.1: the loose-lexing profile of one non-PHP text language family.
 *
 * A profile is DATA, not code: which byte sequences open a line comment,
 * which pairs delimit a block comment, whether a backtick opens a string,
 * and which words the language treats as structural keywords. Keywords
 * survive blanking (they carry the skeleton's punctuation-equivalent
 * structure: `if`, `function`, `{% for %}`-style tags), everything else
 * identifier-shaped blanks to 'I' so a renamed twin still matches.
 *
 * @see \Phpdup\Text\LangProfiles for the shipped extension table
 * @see \Phpdup\Text\TextTokenizer for how the fields are consumed
 */
final class LangProfile
{
    /**
     * @param string[]      $lineComments  sequences opening a to-EOL comment (e.g. ['//'])
     * @param string[][]    $blockComments [open, close] pairs of block comments
     * @param bool          $backtick      whether `...` opens a string literal
     * @param string[]      $keywords      lower-cased words kept through blanking
     */
    public function __construct(
        public readonly string $name,
        public readonly array $lineComments = [],
        public readonly array $blockComments = [],
        public readonly bool $backtick = false,
        public readonly array $keywords = [],
    ) {
    }

    /** @var array<string,true>|null lower-cased keyword lookup, minted lazily */
    private ?array $keywordSet = null;

    /**
     * Lookup form of {@see $keywords} (lower-cased word => present).
     *
     * @return array<string,true>
     */
    public function keywordSet(): array
    {
        return $this->keywordSet ??= array_fill_keys($this->keywords, true);
    }
}
