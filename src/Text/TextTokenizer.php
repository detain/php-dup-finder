<?php
declare(strict_types=1);

namespace Phpdup\Text;

/**
 * Item 7.1: the loose lexer of the native token tier.
 *
 * Deliberately NOT a parser — one flat scanner: line/block comments per
 * the profile are dropped (their content is never clone evidence in the
 * 7.1 contract), identifiers/numbers/strings are recognized for blanking,
 * and every other byte becomes a single-character punctuation token (the
 * structural skeleton). Nothing throws on malformed input; a truncated
 * string literal ends at its line, a truncated comment ends at EOF, and
 * an empty file simply lexes to nothing.
 *
 * The blanked stream this produces is the tier's comparison alphabet:
 * keywords keep their lower-cased spelling, identifiers collapse to 'I',
 * numbers to 'N', string bodies to 'S'. That is what makes
 * buy_license.js ≡ buy_license2.js: renamed declarations and re-valued
 * literals are invisible to the stream, while `function ( ) { return ; }`
 * shape is not.
 */
final class TextTokenizer
{
    /**
     * @return list<TextToken> tokens in source order (never empty by accident —
     *                          empty input yields an empty list, honestly)
     */
    public static function tokenize(string $src, LangProfile $profile): array
    {
        $len = strlen($src);
        if ($len === 0) {
            return [];
        }

        $keywords = $profile->keywordSet();
        $tokens   = [];
        $i        = 0;
        $line     = 1;

        while ($i < $len) {
            $c = $src[$i];

            // whitespace -------------------------------------------------
            if ($c === "\n") {
                $line++;
                $i++;
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\x0B" || $c === "\x0C") {
                $i++;
                continue;
            }

            // comments ---------------------------------------------------
            $skipped = false;
            foreach ($profile->lineComments as $seq) {
                if (substr($src, $i, strlen($seq)) === $seq) {
                    $nl = strpos($src, "\n", $i);
                    $i  = $nl === false ? $len : $nl; // the \n itself bumps the line next pass
                    $skipped = true;
                    break;
                }
            }
            if ($skipped) {
                continue;
            }
            foreach ($profile->blockComments as [$open, $close]) {
                if (substr($src, $i, strlen($open)) === $open) {
                    $closePos = strpos($src, $close, $i + strlen($open));
                    $end      = $closePos === false ? $len : $closePos + strlen($close);
                    $line    += substr_count($src, "\n", $i, $end - $i);
                    $i        = $end;
                    $skipped  = true;
                    break;
                }
            }
            if ($skipped) {
                continue;
            }

            // identifier / keyword ----------------------------------------
            if (preg_match('/[A-Za-z_$][A-Za-z0-9_$]*/A', $src, $m, 0, $i) === 1) {
                $text  = $m[0];
                $start = $i;
                $i    += strlen($text);
                $lower = strtolower($text);
                $tokens[] = isset($keywords[$lower])
                    ? new TextToken(TextToken::KIND_KEYWORD, $text, $line, $start, $i, $lower)
                    : new TextToken(TextToken::KIND_IDENT, $text, $line, $start, $i, 'I');
                continue;
            }

            // number ------------------------------------------------------
            if (
                ($c >= '0' && $c <= '9' && preg_match('/[0-9][0-9a-zA-Z_.]*/A', $src, $m, 0, $i) === 1)
                || ($c === '.' && isset($src[$i + 1]) && $src[$i + 1] >= '0' && $src[$i + 1] <= '9'
                    && preg_match('/\.[0-9][0-9a-zA-Z_.]*/A', $src, $m, 0, $i) === 1)
            ) {
                $text  = $m[0];
                $start = $i;
                $i    += strlen($text);
                $tokens[] = new TextToken(TextToken::KIND_NUMBER, $text, $line, $start, $i, 'N');
                continue;
            }

            // string literal (quote-pair scan with backslash escapes) -----
            if ($c === "'" || $c === '"' || ($c === '`' && $profile->backtick)) {
                $start     = $i;
                $startLine = $line;
                $i++;
                while ($i < $len) {
                    $d = $src[$i];
                    if ($d === '\\' && $i + 1 < $len) {
                        if ($src[$i + 1] === "\n") {
                            $line++;
                        }
                        $i += 2;
                        continue;
                    }
                    if ($d === $c) {
                        $i++;
                        break;
                    }
                    if ($d === "\n") {
                        if ($c !== '`') {
                            break; // unterminated ' " ends at its line; the \n is the next scanner's
                        }
                        $line++;
                        $i++;
                        continue;
                    }
                    $i++;
                }
                $tokens[] = new TextToken(TextToken::KIND_STRING, substr($src, $start, $i - $start), $startLine, $start, $i, 'S');
                continue;
            }

            // punctuation / operators ---------------------------------------
            $tokens[] = new TextToken(TextToken::KIND_PUNCT, $c, $line, $i, $i + 1, $c);
            $i++;
        }

        return $tokens;
    }
}
