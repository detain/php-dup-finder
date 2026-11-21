<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Shared prose phrases for headline counts (ui-grammar: canonical home of
 * the p0c-m2 rule first introduced as CliReporter::fileCount in cb64cdd).
 *
 * Prose render surfaces must agree on grammar: a lone offender reads
 * "1 file", never "1 files". Labelled columnar lines ("files  4 scanned")
 * keep fixed header nouns and are NOT candidates for these phrases.
 */
final class CountPhrase
{
    /** Rendered "N file"/"N files" phrase, singular exactly at one. */
    public static function files(int $count): string
    {
        return sprintf('%d %s', $count, $count === 1 ? 'file' : 'files');
    }
}
