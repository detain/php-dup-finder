<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

/**
 * Cheap, dependency-free extractor of `(verb, primary_table)` from a
 * raw SQL string.
 *
 * The full SQL grammar is far too rich to parse here without a real
 * dependency. We sidestep that: the only signal we feed back into
 * canonicalisation is the **verb** (SELECT / INSERT / UPDATE / DELETE
 * / REPLACE) and the **primary table** referenced in the FROM /
 * INTO / UPDATE / JOIN clause.
 *
 * That is enough to fold:
 *
 *   - `SELECT * FROM users WHERE id = ?`
 *   - `select id, name from users where id = $1`
 *   - `select count(*) from users where active = 1`
 *
 * …into the same canonical token (`__DB_QUERY__("SELECT", "users")`).
 *
 * For pathological inputs (CTEs, sub-selects, dynamically built
 * queries) the extractor returns `null` and the surrounding visitor
 * leaves the call alone, falling back to the AST-based scoring tiers.
 *
 * The extractor is **deliberately permissive**: it tolerates
 * back-tick / double-quote / square-bracket identifier quoting,
 * leading `IGNORE` / `LOW_PRIORITY` modifiers in INSERTs, and the
 * `INTO` keyword being optional on REPLACE.
 *
 * @internal Used by {@see DbOpCanonicalizer}.
 */
final class SqlTableExtractor
{
    /**
     * Parse a raw SQL string and return a `[verb, table]` tuple, or
     * `null` if no recognisable verb is found.
     *
     * @return array{0:string,1:string|null}|null
     *         [0] is the upper-cased verb (SELECT/INSERT/UPDATE/DELETE/REPLACE).
     *         [1] is the lower-cased primary table, or null when not extractable.
     */
    public static function extract(string $sql): ?array
    {
        $trimmed = self::stripCommentsAndCollapseWhitespace($sql);
        if ($trimmed === '') {
            return null;
        }

        // Skip leading WITH ... AS (...) — common-table-expression — and
        // surface the inner SELECT/INSERT/UPDATE/DELETE.
        $trimmed = self::stripLeadingCte($trimmed);

        $verb = self::firstKeyword($trimmed);
        if ($verb === null) {
            return null;
        }

        return match ($verb) {
            'SELECT'  => ['SELECT',  self::extractAfter($trimmed, 'FROM')],
            'INSERT'  => ['INSERT',  self::extractAfter($trimmed, 'INTO')],
            'REPLACE' => ['REPLACE', self::extractAfterOptional($trimmed, 'INTO')],
            'UPDATE'  => ['UPDATE',  self::extractAfter($trimmed, 'UPDATE')],
            'DELETE'  => ['DELETE',  self::extractAfter($trimmed, 'FROM')],
            'TRUNCATE' => ['DELETE', self::extractAfterOptional($trimmed, 'TABLE')],
            default   => null,
        };
    }

    /** Remove `/* ... *\/` and `-- ...` comments, then collapse whitespace. */
    private static function stripCommentsAndCollapseWhitespace(string $sql): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        return $sql;
    }

    /** Strip `WITH cte AS (...) [, cte2 AS (...) ]` so verb detection sees the inner statement. */
    private static function stripLeadingCte(string $sql): string
    {
        if (!preg_match('/^WITH\s+/i', $sql)) {
            return $sql;
        }
        // Walk past balanced parens to find the start of the inner verb.
        // A WITH clause cannot open its first AS body before any string
        // literal, so the first `(` is the body opener; everything past it
        // is scanned with string-literal spans skipped (see findBodyEnd).
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            // Find next opening paren for the AS (...) body.
            $open = strpos($sql, '(', $i);
            if ($open === false) {
                return $sql;
            }
            $close = self::findBodyEnd($sql, $open);
            if ($close === null) {
                // Unbalanced parens or an unterminated string — do not
                // trust a strip; leave the statement alone so verb
                // detection degrades to null instead of surfacing a
                // mid-query fragment.
                return $sql;
            }
            // After the closing paren we have ", cte2 AS (...)" or the inner verb.
            $j = $close + 1;
            if ($j < $len && $sql[$j] === ',') {
                $i = $j + 1;
                continue;
            }
            return ltrim(substr($sql, $j));
        }
        return $sql;
    }

    /**
     * Index of the `)` closing the paren opened at $open. Depth is counted
     * outside `'…'`, `"…"` and `` `…` `` spans: a CTE body holding a
     * string literal with an unbalanced paren (`'a(b'`) must not desync
     * the walker.
     *
     * @return int|null null when the statement never balances (unterminated
     *                  literal or stray paren) — callers must treat that
     *                  as "cannot strip".
     */
    private static function findBodyEnd(string $sql, int $open): ?int
    {
        $len = strlen($sql);
        $depth = 0;
        for ($i = $open; $i < $len; $i++) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $i = self::skipQuotedSpan($sql, $i);
                if ($i < 0) {
                    return null; // unterminated literal
                }
                continue; // $i sits on the closing quote; the loop steps past it
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
                if ($depth < 0) {
                    return null; // stray closer before we ever opened
                }
            }
        }
        return null; // ran out of statement with parens still open
    }

    /**
     * Last index of the quoted span that starts at $start, so a caller's
     * loop increment lands just past the closing quote. Doubled quotes
     * (`'it''s'`, ANSI style) and, for `'`/`"`, backslash escapes (MySQL
     * style) stay inside the span; backticks take no backslash escapes.
     *
     * @return int -1 when the span never closes.
     */
    private static function skipQuotedSpan(string $sql, int $start): int
    {
        $quote = $sql[$start];
        $len = strlen($sql);
        for ($i = $start + 1; $i < $len; $i++) {
            $c = $sql[$i];
            if ($c === '\\' && $quote !== '`') {
                $i++; // escape consumes the next character
                continue;
            }
            if ($c === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $i++; // doubled quote is an escaped quote, span continues
                    continue;
                }
                return $i;
            }
        }
        return -1;
    }

    /** Return the upper-cased first keyword token of $sql, or null. */
    private static function firstKeyword(string $sql): ?string
    {
        if (!preg_match('/^(\w+)/', $sql, $m)) {
            return null;
        }
        return strtoupper($m[1]);
    }

    /**
     * Extract the identifier following a required keyword (e.g. FROM,
     * INTO). Returns null when the keyword is absent or the token
     * after it is not an identifier.
     */
    private static function extractAfter(string $sql, string $keyword): ?string
    {
        $kw = preg_quote($keyword, '/');
        if (!preg_match('/\b' . $kw . '\s+(.+?)(?:\s|$)/i', $sql, $m)) {
            return null;
        }
        return self::cleanIdentifier($m[1]);
    }

    /**
     * Same as {@see extractAfter()} but the keyword is optional —
     * for REPLACE [INTO] / TRUNCATE [TABLE] grammars.
     */
    private static function extractAfterOptional(string $sql, string $keyword): ?string
    {
        $found = self::extractAfter($sql, $keyword);
        if ($found !== null) {
            return $found;
        }
        // Fallback: take the first identifier after the verb itself.
        if (!preg_match('/^\w+\s+(.+?)(?:\s|$)/i', $sql, $m)) {
            return null;
        }
        return self::cleanIdentifier($m[1]);
    }

    /**
     * Strip surrounding identifier-quote characters (`"foo"`,
     * `` `foo` ``, `[foo]`), trim trailing punctuation (`,`, `(`),
     * lower-case, and reject obviously-bad inputs (anything containing
     * a `?` or `$N` placeholder, parens, whitespace, or quote/bracket
     * residue that failed to balance-strip).
     */
    private static function cleanIdentifier(string $raw): ?string
    {
        // Strip trailing punctuation that may have followed in regex
        // capture — BEFORE quote detection, so a capture like `[users],`
        // still presents a balanced `[users]` to the bracket stripper
        // instead of leaking bracket residue as the table name.
        $raw = rtrim(trim($raw), ",;()");
        if ($raw === '' || preg_match('/[?\s$()]/', $raw)) {
            return null;
        }
        // Schema-qualified identifiers: take last segment after the dot.
        if (str_contains($raw, '.')) {
            $parts = explode('.', $raw);
            $raw = (string)end($parts);
        }
        // Drop quoting on the (possibly last) segment.
        $raw = self::stripIdentifierQuotes($raw);
        // Any surviving quote/bracket character means an unbalanced or
        // embedded quote — we cannot name this table reliably, so degrade
        // to null instead of flowing a bogus identifier into the DB-op
        // canonical stream and clustering.
        if ($raw === '' || preg_match('/[\[\]"`]/', $raw)) {
            return null;
        }
        return strtolower($raw);
    }

    /** Remove one layer of surrounding identifier quoting: `` `f` ``, `"f"` or `[f]`. */
    private static function stripIdentifierQuotes(string $token): string
    {
        if ($token === '') {
            return $token;
        }
        $closer = match ($token[0]) {
            '`'  => '`',
            '"'  => '"',
            '['  => ']',
            default => null,
        };
        if ($closer !== null && strlen($token) > 1 && str_ends_with($token, $closer)) {
            return substr($token, 1, -1);
        }
        return $token;
    }
}
