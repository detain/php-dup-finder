<?php
declare(strict_types=1);

namespace Phpdup\Scanning;

use Phpdup\Util\PathCanonical;

/**
 * Recursive PHP file scanner with glob include/exclude semantics.
 *
 * Patterns are matched against the path relative to the scan root. The
 * dialect is segment-safe (gitignore-style): NO star form crosses a path
 * separator by accident — see {@see globToRegex()} for the full table.
 *
 * Unreadable trees (item 5.2 / NN2) are skipped with a visible stderr note
 * and recorded in {@see skipNotes()}; they never abort the scan nor the
 * run. This mirrors the walk-hard-die cure f326b06 applied to
 * ConfigLoader's per-directory discovery: config errors stay loud,
 * environment errors stay non-fatal — but here they stay INFORMATIVE too.
 *
 * Symlink containment (shell-006): the walk follows symlinks, but never out
 * of the tree. Every symlinked entry is canonicalized (realpath) and
 * compared against the real scan root; a link whose target resolves
 * outside the root, re-enters it (self-loop), or does not resolve at all
 * is pruned with a skip note instead of being traversed or yielded. Links
 * to in-root targets keep working. This complements — not replaces — the
 * serve-root confinement in {@see \Phpdup\Server\Application}: belt AND
 * braces.
 *
 * Bounded matching (once-095, sugar-ignore UP-01 port): an exclude pattern
 * is hostile input — it arrives from a scanned repository's .phpdup.json
 * as readily as from the user's own flags. Patterns whose wildcards PCRE
 * cannot safely retry are decided by a backtrack-free automaton instead of
 * preg_match, a PCRE resource-limit error on any other pattern falls
 * through to the SAME automaton rather than reading as a silent "no
 * match", and a pattern too large even for the automaton's step budget is
 * resolved toward EXCLUDING (fail CLOSED) and reported once through
 * skipNotes(). A hostile config can hide paths behind a visible note; it
 * can neither wedge the scan nor silently void its own excludes.
 *
 * Per-directory overlay (once-S28 / php-dup-finder-once-032): the scan walk
 * can additionally be fed the exclude layers discovered in nested
 * `.phpdup.json` files (keys: the absolute directory ConfigLoader keyed;
 * values: that file's own `exclude` list + `exclude_reset` flag). Such a
 * list is anchored in ITS OWN DIRECTORY's frame — patterns match paths
 * relative to the config's directory, compiled with the same segment-safe
 * dialect — and chains with everything above it, shallow→deep, appending
 * unless it resets. A resetting layer discards everything inherited below
 * it (the root list and ancestors alike), mirroring Config::effectiveFor().
 * Excluded directories are pruned silently, exactly as root-excluded ones,
 * UNLESS a resetting layer sits at or below the pruned directory — that
 * config re-roots its subtree, so the walk descends and the per-entry
 * verdicts inside get their chance to flip. The overlay governs scan()
 * only: isExcluded() and the ConfigLoader discovery gate stay root-list
 * matchers, so a tree excluded at the ROOT is still never read, never
 * noted, and can never be resurrected by a config hidden inside it.
 *
 * Honest scan signals (once-S37 / php-dup-finder-once-041, once-080): every
 * path the scan cannot use now answers on the skipNotes() channel — unreadable
 * FILES joined the unreadable directories (the old walk yielded them and let a
 * raw read-time warning leak past every channel this class owns), and a
 * missing scan root is noted where it once returned empty-handed. Above the
 * channel sits an exclude-typo census: a caller arms
 * {@see watchExcludePatterns()} with the user-supplied globs it owns, every
 * walked path marks the patterns that matched, and
 * {@see inertWatchPatterns()} reports each never-matched pattern — once, on
 * the same 'phpdup: warning:' stderr voice as every skip. A typo'd exclude
 * can no longer quietly expose the tree it was meant to hide.
 *
 * .gitignore honoring (item 5.2's named missing leg, W2a): when built with
 * honorGitignore: true the walk additionally consults `.gitignore` files —
 * the scan root's own plus every nested one it reaches while descending.
 * The dialect is gitignore(5) core: last-matching-line-wins per file,
 * `!` negation, directory-only trailing `/`, root-anchoring leading `/`
 * (any interior slash also anchors a pattern to its own directory), the
 * `**` forms {@see tokenizeGlob()} already compiles, `[...]` character
 * classes, and backslash escapes. A pattern without a slash matches the
 * entry's LAST PATH SEGMENT at any depth. Across files, the deepest
 * .gitignore that answers wins — a negation in /sub re-includes what the
 * root file excluded. A directory the verdict excludes is pruned before
 * descent, so its inner .gitignore files are never read and no negation
 * revives a path under an excluded parent: git's own documented
 * limitation, mirrored here on purpose. The two channels never override
 * each other — an exclude-list pruned entry stays pruned silently and
 * can neither be revived by `!` nor grow a governing ignore file.
 * `.git/info/exclude` and `core.excludesFile` are out of scope (the doc
 * letter names .gitignore files), as is walking above the scan root: run
 * phpdup at the root whose ignores you mean to honor. Verdicts are silent
 * per path (a vendor-sized tree would blow memory on note bytes); each
 * drained root instead adds ONE aggregate count to the honest-scan
 * channel ({@see skipNotes()}) and {@see gitignoreSkippedCount()} answers
 * for the run. Hostile inputs stay bounded: file-size and line-count caps
 * fail CLOSED for that file (visibly, like an over-budget exclude pattern),
 * and cache caps fail OPEN once (visibly too — refusing a whole repo at
 * the 5,001st ignore file would destroy more trust than it protects).
 * Default OFF: without the flag the walk stays byte-identical to every
 * historical pin.
 *
 * @phpstan-type GitignoreClasses array<int, array{0: bool, 1: list<array{0: int, 1: int}>}>
 * @phpstan-type GitignoreMatch array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool, classes?: GitignoreClasses}
 * @phpstan-type GitignoreRule array{negate: bool, dirOnly: bool, anchored: bool, match: GitignoreMatch}
 * @psalm-type GitignoreClasses = array<int, array{0: bool, 1: list<array{0: int, 1: int}>}>
 * @psalm-type GitignoreMatch = array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool, classes?: GitignoreClasses}
 * @psalm-type GitignoreRule = array{negate: bool, dirOnly: bool, anchored: bool, match: GitignoreMatch}
 */
final class FileScanner
{
    /** Token kinds of the compiled glob dialect (see {@see globToRegex()}). */
    private const TOKEN_LITERAL = 0; // a literal byte
    private const TOKEN_ONE = 1;     // [^/]
    private const TOKEN_STAR = 2;    // [^/]*  (within one segment)
    private const TOKEN_SEG = 3;     // (?:[^/]*/)*  (zero or more whole segments)
    private const TOKEN_ANY = 4;     // .*  (trailing **, crosses segments)
    private const TOKEN_CLASS = 5;   // [abc] / [!abc] (single byte, gitignore classes)

    /**
     * .gitignore honoring budgets (W2a). A planted ignore file is hostile
     * input on the once-095 theory, so size/line caps fail CLOSED for that
     * one file — the subtree is excluded and the note says so, the same
     * "treated as excluded, loudly" posture as an undecidable exclude
     * pattern. The caches are operational bounds, not safety verdicts:
     * past them later files are simply not honored, noted once, because
     * silently devouring a legitimately huge tree would be the worse lie.
     */
    public const GITIGNORE_BYTES_CAP = 1_048_576;   // 1 MiB per file
    public const GITIGNORE_LINES_CAP = 10_000;      // lines parsed per file
    public const GITIGNORE_FILES_CAP = 5_000;       // parsed ignore files per scan()
    public const GITIGNORE_MISS_CAP = 100_000;      // cached "no file here" answers

    /**
     * Upper bound on path length × pattern tokens one automaton match may
     * cost. Sized so no plausible rule reaches it — a 200-token pattern
     * against a PATH_MAX (4,096-byte) path is 820k — while a pathological
     * line, which is all that can, is refused before any work rather than
     * after it (ported, like the whole budget, from sugar-ignore).
     */
    public const MATCH_BUDGET = 1_000_000;

    /** DFA states one pattern's automaton may intern before it is rebuilt. */
    private const STATE_CAP = 4096;

    /** How much of one undecidable pattern the skip note quotes. */
    private const NOTE_PATTERN_MAX_BYTES = 80;

    /** @var list<array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool}> */
    private array $patterns;

    /**
     * Interned DFAs, keyed by compiled regex so twenty copies of one hostile
     * pattern share one automaton.
     *
     * @var array<string, array{ids: array<string, int>, sets: list<list<int>>, accepts: list<bool>, next: array<int, array<string, int>>}>
     */
    private array $automata = [];

    /** @var array<string, true> regexes already reported as undecidable (report-once) */
    private array $undecidableNoted = [];

    /** @var list<string> human-readable notes for every skipped path */
    private array $skipNotes = [];

    /**
     * Entries the .gitignore verdict excluded across every scan() this
     * instance ran (W2a). Directories count once — their pruned descendants
     * were never visited, exactly like the walk itself. Honest accounting
     * without a per-path note: a vendor-sized tree would otherwise mint a
     * note per file and blow the memory the notes exist to protect.
     */
    public function gitignoreSkippedCount(): int
    {
        return $this->gitignoreSkipped;
    }

    /**
     * The .gitignore verdict for one walked entry (W2a / item 5.2): walk the
     * ancestor chain of directories whose `.gitignore` files govern the
     * entry, deepest first, and let the first file that answers decide —
     * within one file, the LAST matching line wins (git's precedence rules,
     * gitignore(5)). A directory entry's chain starts at its PARENT: a
     * directory's own .gitignore governs its contents, never the directory
     * itself.
     *
     * The test frame follows the dialect: an unanchored pattern (no slash
     * once the leading-slash/ dir-marker strips are done) matches the
     * entry's LAST PATH SEGMENT at any depth; an anchored pattern (leading
     * '/', or any slash in the pattern body after the strips) matches the
     * entry path RELATIVE TO THE GOVERNING FILE'S DIRECTORY — which for a
     * directory entry is the basename form WITHOUT a trailing slash, so
     * `build` matches a directory `build` exactly like git, and `build/`
     * matches it via dirOnly in the same frame.
     *
     * @param array<string, ?list<GitignoreRule>> $cache
     * @param array{files: int, filesNoted: bool, misses: int} $stats
     */
    private function gitignoredPath(string $rel, bool $isDir, string $root, array &$cache, array &$stats): bool
    {
        $slash = strrpos($rel, '/');
        $base = $slash === false ? $rel : substr($rel, $slash + 1);
        // dirname() already answers BOTH entry kinds correctly: for a file,
        // its own directory is the deepest governing file; for a directory,
        // dirname is its PARENT — a directory's own .gitignore governs its
        // contents, never itself (git's rule).
        $dir = $slash === false ? '' : substr($rel, 0, $slash);
        for (;;) {
            $rules = $this->gitignoreRules($dir, $root, $cache, $stats);
            if ($rules !== null) {
                // git's within-file precedence: the LAST matching line wins,
                // negation included — scan reversed and stop at the first hit.
                for ($i = count($rules) - 1; $i >= 0; $i--) {
                    $rule = $rules[$i];
                    if ($rule['dirOnly'] && !$isDir) {
                        continue; // a directory-only pattern never answers for a file
                    }
                    if ($rule['anchored']) {
                        $subject = $dir === '' || !str_starts_with($rel, $dir . '/')
                            ? $rel
                            : substr($rel, strlen($dir) + 1);
                    } else {
                        $subject = $base;
                    }
                    if ($this->matchesPattern($rule['match'], $subject)) {
                        return !$rule['negate'];
                    }
                }
            }
            if ($dir === '') {
                break;
            }
            $up = strrpos($dir, '/');
            $dir = $up === false ? '' : substr($dir, 0, $up);
        }

        return false;
    }

    /**
     * The parsed rule list for one directory's .gitignore, or null when no
     * usable file governs it. Absent/unreadable files answer null and are
     * cached as such under a hard miss cap — the caches are operational
     * bounds with visible cap notes, not safety verdicts (class docblock):
     * unreadable = environmental, the NN2 skip-with-note posture;
     * over-budget CONTENT = hostile input, once-095 fail-closed posture
     * (the whole file is replaced by a single `*` exclusion plus a note) —
     * reading only the first N lines of a last-match-wins dialect would
     * silently mis-apply it, the exact fail-open sugar-ignore minted.
     *
     * @param array<string, ?list<GitignoreRule>> $cache
     * @param array{files: int, filesNoted: bool, misses: int} $stats
     * @return ?list<GitignoreRule>
     */
    private function gitignoreRules(string $dir, string $root, array &$cache, array &$stats): ?array
    {
        if (array_key_exists($dir, $cache)) {
            return $cache[$dir];
        }
        $file = $root . ($dir === '' ? '' : '/' . $dir) . '/.gitignore';
        if (!is_file($file)) {
            if ($stats['misses'] < self::GITIGNORE_MISS_CAP) {
                $cache[$dir] = null;
                $stats['misses']++;
            }
            return null;
        }
        if ($stats['files'] >= self::GITIGNORE_FILES_CAP) {
            if (!$stats['filesNoted']) {
                $stats['filesNoted'] = true;
                $this->addSkipNote(
                    'gitignore: only the first ' . self::GITIGNORE_FILES_CAP
                    . ' ignore files are honored; later ones under scan root ' . $root . ' are not'
                );
            }
            return null; // uncached: the cap answers per call, one note total
        }
        $stats['files']++;

        $size = @filesize($file);
        if ($size !== false && $size > self::GITIGNORE_BYTES_CAP) {
            return $this->gitignoreBlockedAll($file, 'exceeds the ' . self::GITIGNORE_BYTES_CAP . '-byte budget');
        }
        $text = @file_get_contents($file);
        if ($text === false) {
            $this->noteSkip($file, 'ignore rules could not be read and are treated as absent');
            if ($stats['misses'] < self::GITIGNORE_MISS_CAP) {
                $cache[$dir] = null;
                $stats['misses']++;
            }
            return null;
        }

        $truncated = false;
        $rules = self::parseGitignore($text, $truncated);
        if ($truncated) {
            return $this->gitignoreBlockedAll($file, 'exceeds the ' . self::GITIGNORE_LINES_CAP . '-line budget');
        }
        if ($rules === []) {
            if ($stats['misses'] < self::GITIGNORE_MISS_CAP) {
                $cache[$dir] = null;
                $stats['misses']++;
            }
            return null;
        }
        $cache[$dir] = $rules;

        return $rules;
    }

    /**
     * The fail-CLOSED answer for one over-budget ignore file: a lone `*`
     * exclusion — non-anchored, both entry kinds — so every path BELOW the
     * file's directory is excluded until some deeper file's `!` revives it,
     * and the note says why. Replacing the file with 'its first N lines'
     * would be the quiet-wrong answer; excluding everything is the loud one.
     *
     * @return non-empty-list<GitignoreRule>
     */
    private function gitignoreBlockedAll(string $file, string $why): array
    {
        $this->addSkipNote('gitignore file ' . $file . ' ' . $why . '; paths below it are treated as excluded');
        $tokens = self::tokenizeGlob('*');
        return [[
            'negate' => false,
            'dirOnly' => false,
            'anchored' => false,
            'match' => [
                'source' => '*',
                'regex' => self::regexFor($tokens),
                'tokens' => $tokens,
                'pcre' => self::backtracksSafely($tokens),
            ],
        ]];
    }

    /**
     * Parse one .gitignore body into ordered rules (order = file order; the
     * verdict loop scans reversed for last-match-wins). Line dialect,
     * gitignore(5):
     *  • blank lines and leading-# comments are skipped (a mid-line # is a
     *    literal byte);
     *  • trailing spaces are trimmed unless backslash-escaped;
     *  • CR is stripped (a CRLF-written ignore file still works);
     *  • a leading ! negates; \# and \! spell the literal first bytes;
     *  • a trailing / marks directory-only; a leading / anchors to this
     *    file's directory, and ANY slash left in the body anchors too
     *    (git's "if there is a separator at the beginning or middle, the
     *    pattern is relative to the directory level of the particular
     *    .gitignore file");
     *  • the body matches through the SAME segment-safe glob engine as the
     *    exclude lists — `*`/`?`/the `**` forms — plus `[...]` classes and
     *    backslash escapes, which the exclude dialect never sees.
     * Declined (each defensible, all rare-to-pathological): .git/info/exclude
     * and core.excludesFile (out of the doc letter), POSIX class collations
     * [:alpha:] (git's own wildmatch lacks them), and a ']' as the FIRST
     * class member is literal, matching git/wildmatch byte-for-byte.
     *
     * @param-out bool $truncated
     * @return list<GitignoreRule>
     */
    private static function parseGitignore(string $text, bool &$truncated): array
    {
        $lines = explode("\n", $text);
        $truncated = count($lines) > self::GITIGNORE_LINES_CAP;
        if ($truncated) {
            return [];
        }
        $rules = [];
        foreach ($lines as $line) {
            $body = self::trimGitignoreSpaces(rtrim($line, "\r"));
            if ($body === '' || $body[0] === '#') {
                continue;
            }
            $negate = false;
            if ($body[0] === '!') {
                $negate = true;
                $body = substr($body, 1);
                if ($body === '') {
                    continue; // a lone '!' is no pattern
                }
            }
            $dirOnly = false;
            if (strlen($body) > 1 && $body[strlen($body) - 1] === '/' && !self::gitignoreByteEscaped($body, strlen($body) - 1)) {
                $dirOnly = true;
                $body = substr($body, 0, -1);
            }
            $anchored = false;
            if ($body !== '' && $body[0] === '/') {
                $anchored = true;
                $body = substr($body, 1);
            }
            if ($body === '' || $body === '/') {
                continue;
            }
            if (str_contains($body, '/')) {
                $anchored = true;
            }
            [$tokens, $classes] = self::tokenizeGitignore($body);
            if ($tokens === []) {
                continue;
            }
            $rules[] = [
                'negate' => $negate,
                'dirOnly' => $dirOnly,
                'anchored' => $anchored,
                'match' => [
                    'source' => $body,
                    'regex' => self::regexFor($tokens),
                    'tokens' => $tokens,
                    'pcre' => self::backtracksSafely($tokens),
                    'classes' => $classes,
                ],
            ];
        }

        return $rules;
    }

    /**
     * Trailing-space trim per gitignore(5): spaces count as trimmed while
     * UNESCAPED — an odd number of immediately preceding backslashes pins
     * the space as data instead.
     */
    private static function trimGitignoreSpaces(string $line): string
    {
        $end = strlen($line);
        while ($end > 0 && $line[$end - 1] === ' ' && !self::gitignoreByteEscaped($line, $end - 1)) {
            $end--;
        }

        return substr($line, 0, $end);
    }

    /**
     * Is byte $at preceded by an odd (escaping) number of backslashes?
     */
    private static function gitignoreByteEscaped(string $text, int $at): bool
    {
        $backslashes = 0;
        for ($j = $at - 1; $j >= 0 && $text[$j] === '\\'; $j--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }

    /**
     * Tokenize a slash-anchored .gitignore body for the shared matchers.
     * Same star trichotomy as {@see tokenizeGlob()} (this file's `**` forms
     * are already exactly the glob dialect), plus the two gitignore-only
     * features that dialect lacks: backslash escapes (any metabyte becomes
     * a literal) and `[...]` character classes. Classes arrive as a
     * prebuilt PCRE group payload plus a position-keyed membership map for
     * the backtrack-free automaton — the two matchers keep one source of
     * truth, so they cannot drift (the same law regexFor enforces for globs).
     *
     * @return array{0: list<array{0: int, 1: string}>, 1: array<int, array{0: bool, 1: list<array{0: int, 1: int}>}>}
     */
    private static function tokenizeGitignore(string $body): array
    {
        $tokens = [];
        $classes = [];
        $i = 0;
        $len = strlen($body);
        while ($i < $len) {
            $c = $body[$i];
            if ($c === '\\') {
                if ($i + 1 < $len) {
                    $tokens[] = [self::TOKEN_LITERAL, $body[$i + 1]]; // escaped: '*','?','#','!','[',space…
                    $i += 2;
                } else {
                    $tokens[] = [self::TOKEN_LITERAL, '\\']; // trailing lone backslash is data
                    $i++;
                }
                continue;
            }
            if ($c === '*') {
                if ($i + 1 < $len && $body[$i + 1] === '*') {
                    while ($i < $len && $body[$i] === '*') {
                        $i++;
                    }
                    if ($i >= $len) {
                        $tokens[] = [self::TOKEN_ANY, ''];
                    } elseif ($body[$i] === '/') {
                        $i++;
                        $lastType = $tokens === [] ? null : $tokens[count($tokens) - 1][0];
                        if ($lastType !== self::TOKEN_SEG) {
                            $tokens[] = [self::TOKEN_SEG, ''];
                        }
                    } else {
                        $tokens[] = [self::TOKEN_STAR, '']; // ** off-boundary = one *
                    }
                } else {
                    $tokens[] = [self::TOKEN_STAR, ''];
                    $i++;
                }
                continue;
            }
            if ($c === '?') {
                $tokens[] = [self::TOKEN_ONE, ''];
                $i++;
                continue;
            }
            if ($c === '[') {
                $class = self::parseGitignoreClass($body, $i, $len);
                if ($class === null) {
                    // fnmatch(7)/git-wildmatch posture: an unterminated '['
                    // is a literal '[' byte, not a compile error.
                    $tokens[] = [self::TOKEN_LITERAL, '['];
                    $i++;
                    continue;
                }
                $classes[count($tokens)] = [$class[1], $class[2]];
                $tokens[] = [self::TOKEN_CLASS, $class[0]];
                $i = $class[3];
                continue;
            }
            $tokens[] = [self::TOKEN_LITERAL, $c];
            $i++;
        }

        return [$tokens, $classes];
    }

    /**
     * Read one bracket class at $start ('['), git/wildmatch semantics:
     * negation is a leading ! (NOT '^' — doc-exact), a ']' as the FIRST
     * member is data, '-' between members is a byte range, backslash pins
     * bytes inside the class. A reversed range matches nothing (git's
     * NOMATCH), expressed to PCRE as the never-group (?!) and to the map as
     * an empty range list. Unclosed (or memberless) → null → caller keeps
     * the '[' as a literal byte.
     *
     * @return ?array{0: string, 1: bool, 2: list<array{0: int, 1: int}>, 3: int}
     *   [regex group text, negated, ranges, index after the closing ']']
     */
    private static function parseGitignoreClass(string $body, int $start, int $len): ?array
    {
        $i = $start + 1;
        $neg = $i < $len && $body[$i] === '!';
        if ($neg) {
            $i++;
        }
        $ranges = [];
        $first = true;
        $never = false;
        while ($i < $len) {
            $c = $body[$i];
            if ($c === ']' && !$first) {
                if ($ranges === []) {
                    return null;
                }
                $group = '[' . ($neg ? '^' : '');
                foreach ($ranges as [$lo, $hi]) {
                    $group .= $lo === $hi
                        ? self::gitignoreClassByte($lo)
                        : self::gitignoreClassByte($lo) . '-' . self::gitignoreClassByte($hi);
                }
                return [$group . ']', $neg, $ranges, $i + 1];
            }
            if ($c === '\\' && $i + 1 < $len) {
                $lo = ord($body[$i + 1]);
                $i += 2;
            } else {
                $lo = ord($c);
                $i++;
            }
            if ($i < $len && $body[$i] === '-' && $i + 1 < $len && $body[$i + 1] !== ']') {
                if ($body[$i + 1] === '\\' && $i + 2 < $len) {
                    $hi = ord($body[$i + 2]);
                    $i += 3;
                } else {
                    $hi = ord($body[$i + 1]);
                    $i += 2;
                }
                if ($hi < $lo) {
                    // reversed range: git's wildmatch answers NOMATCH for the
                    // whole class (negation included — the range itself is
                    // the error, POSIX leaves it undefined, we mirror git).
                    $never = true;
                    break;
                }
                $ranges[] = [$lo, $hi];
            } else {
                $ranges[] = [$lo, $lo];
            }
            $first = false;
        }
        if ($never) {
            // Skip to the closing ']' so no stray class bytes leak out as
            // tokens; the group is the closed never-lookahead and the map
            // entry matches it: no byte advances.
            while ($i < $len && $body[$i] !== ']') {
                if ($body[$i] === '\\' && $i + 1 < $len) {
                    $i += 2;
                    continue;
                }
                $i++;
            }
            if ($i < $len && $body[$i] === ']') {
                return ['(?!)', false, [], $i + 1];
            }
        }

        return null;
    }

    /**
     * A byte rendered safe inside a PCRE class (bracket metas escaped,
     * non-printables as \xHH; high bytes pass raw — the matcher is
     * byte-semantics, git's own dialect).
     */
    private static function gitignoreClassByte(int $byte): string
    {
        if ($byte === 0x5B || $byte === 0x5D || $byte === 0x5E || $byte === 0x2D || $byte === 0x5C) {
            return '\\' . chr($byte);
        }
        if ($byte < 0x20 || $byte === 0x7F) {
            return '\x' . strtoupper(dechex($byte));
        }

        return chr($byte);
    }

    /** @var list<array{dir: string, patterns: list<array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool}>, reset: bool}> */
    private array $layers = [];

    /**
     * Exclude-typo census state (once-S37): the sources armed by
     * {@see watchExcludePatterns()}, the compiled records they select, the
     * subset already proven live by some walked path, and how many subjects
     * the walk has passed since arming — zero witnesses means no directory
     * was ever walked, so no pattern can honestly be called inert.
     *
     * @var array<string, true>
     */
    private array $watchSources = [];

    /** @var list<array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool}> */
    private array $watchedRecords = [];

    /** @var array<string, true> watched sources proven live (report-once bookkeeping) */
    private array $watchMatched = [];

    private int $walkedSubjects = 0;

    /**
     * Entries the .gitignore verdict excluded during every scan() this
     * instance has run (W2a). Directories count once — their pruned
     * descendants were never visited, exactly like the walk itself.
     */
    private int $gitignoreSkipped = 0;

    /**
     * The overlay layers bound to the root currently being walked, anchored
     * in root-relative form and sorted shallow→deep (once-S28). Empty while
     * no scan() with layers is running, so every walk decision falls back
     * to the root-list fast path.
     *
     * @var list<array{rel: string, patterns: list<array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool}>, reset: bool}>
     */
    private array $activeLayers = [];

    /**
     * @param list<string> $excludeGlobs
     * @param bool         $warnOnSkip emit a stderr warning line per skipped
     *                                 path recorded in skipNotes() (in
     *                                 addition to the note itself).
     * @param array<string, array{exclude?: list<string>, reset?: bool}> $perDirExcludes
     *                                 subtree-scoped exclude layers keyed by
     *                                 the absolute directory of each
     *                                 discovered `.phpdup.json` (the realpath
     *                                 form ConfigLoader::discoverPerDirectoryOverrides()
     *                                 emits). Globs are matched relative to
     *                                 that directory, in the same segment-safe
     *                                 dialect; `reset` (the file's
     *                                 `exclude_reset`) discards everything
     *                                 inherited below it. scan() only — see
     *                                 the class docblock.
     * @param bool           $honorGitignore consult `.gitignore` files while
     *                                 walking (W2a / item 5.2): the scan
     *                                 root's own plus every nested one the
     *                                 descent reaches. Runs AFTER the
     *                                 exclude-list verdict (a config-excluded
     *                                 entry is a silent prune on the config
     *                                 channel and no `!` rule revives it);
     *                                 verdicts never enter skipNotes() per
     *                                 path — one aggregate note per drained
     *                                 root and gitignoreSkippedCount() carry
     *                                 the honest accounting. Off by default:
     *                                 the historical walk is untouched.
     * @param list<string> $textExtensions item 7.1 second gate: extensions
     *                                 the armed run admits as tier files
     *                                 (LangProfiles::extensions()); empty
     *                                 keeps the walk byte-identical.
     */
    public function __construct(
        array $excludeGlobs,
        private readonly bool $warnOnSkip = true,
        array $perDirExcludes = [],
        private readonly bool $honorGitignore = false,
        // Item 7.1: the token tier's second admission gate. An EMPTY list
        // (every default run) keeps looksLikeText() false everywhere, so
        // the walk is byte-identical to the pre-7.1 scanner; a non-empty
        // list is the armed run's extension set (LangProfiles::extensions()).
        private readonly array $textExtensions = [],
    ) {
        $this->patterns = array_map([$this, 'compilePattern'], $excludeGlobs);
        foreach ($perDirExcludes as $dir => $layer) {
            $dir = rtrim(PathCanonical::separators((string) $dir), '/');
            if ($dir === '') {
                continue;
            }
            $patterns = [];
            foreach ($layer['exclude'] ?? [] as $glob) {
                $patterns[] = $this->compilePattern($glob);
            }
            $this->layers[] = [
                'dir' => $dir,
                'patterns' => $patterns,
                'reset' => (bool) ($layer['reset'] ?? false),
            ];
        }
        // Chain order is shallow→deep; the segment count of the absolute
        // directory orders the root-relative anchors under any shared root.
        usort(
            $this->layers,
            static fn (array $a, array $b): int => substr_count($a['dir'], '/') <=> substr_count($b['dir'], '/'),
        );
    }

    /**
     * Compile one exclude glob to its full matcher record: the anchored
     * regex, the token list the automaton walks, and the verdict on whether
     * PCRE may run this shape at all (see {@see backtracksSafely()}).
     *
     * @return array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool}
     */
    private function compilePattern(string $glob): array
    {
        // once-044: a config written on Windows may spell its excludes
        // with backslashes; canonicalize once at compile time so regex,
        // tokens and the quoted 'source' all describe the same
        // canonical language. Slash-form globs (every existing pin) are
        // fixed points — the shell-S21 seam stays byte-exact.
        $glob = PathCanonical::separators($glob);
        $tokens = self::tokenizeGlob($glob);
        // The regex goes through the canonical compiler entry — the same
        // function the shell-S21 compile pins reflect into — so the seam
        // and production can never drift. Re-deriving the token list here
        // costs microseconds at construction, never per path.
        $regex = $this->globToRegex($glob);
        return [
            'source' => $glob,
            'regex' => $regex,
            'tokens' => $tokens,
            'pcre' => self::backtracksSafely($tokens),
        ];
    }

    /**
     * Paths (and reasons) the last scan(s) had to skip — permission
     * problems and symlink-containment refusals — in encounter order.
     *
     * @return list<string>
     */
    public function skipNotes(): array
    {
        return $this->skipNotes;
    }

    /**
     * Arm the exclude-typo census (once-S37 / php-dup-finder-once-041).
     * The caller names the user-supplied globs whose typos it wants
     * reported (config file, profile, CLI dict — the stage that merged
     * them owns the attribution; stock defaults and per-directory overlay
     * layers are deliberately left unwatched). Every later walk marks the
     * watched patterns that match a real path; {@see inertWatchPatterns()}
     * answers for the rest. Arming resets the witness state: one census
     * per logical scan run.
     *
     * @param list<string> $sources raw glob strings, as the user wrote them
     */
    public function watchExcludePatterns(array $sources): void
    {
        $wanted = [];
        foreach ($sources as $source) {
            $key = PathCanonical::separators($source);
            if ($key !== '') {
                $wanted[$key] = true;
            }
        }
        $this->watchSources = $wanted;
        $this->watchedRecords = array_values(array_filter(
            $this->patterns,
            static fn (array $pattern): bool => isset($wanted[$pattern['source']]),
        ));
        $this->watchMatched = [];
        $this->walkedSubjects = 0;
    }

    /**
     * Watched patterns that matched no path since the census was armed
     * (once-S37). Each newly reported pattern also lands in skipNotes()
     * — one 'phpdup: warning:' line per typo, never a repeat on later
     * calls (report-once, the same bookkeeping shape as
     * {@see noteUndecidable()}). A run that walked no directory answers
     * empty: an un-walked tree cannot prove a pattern inert, and the
     * file-root path never enters the walk.
     *
     * @return list<string> the notes added by this call
     */
    public function inertWatchPatterns(): array
    {
        if ($this->watchedRecords === [] || $this->walkedSubjects === 0) {
            return [];
        }
        $notes = [];
        foreach ($this->watchedRecords as $pattern) {
            $source = $pattern['source'];
            if (isset($this->watchMatched[$source])) {
                continue;
            }
            $this->watchMatched[$source] = true;
            $note = 'exclude pattern ' . self::printablePattern($source)
                . ' matched no path in this scan (typo?)';
            $this->addSkipNote($note);
            $notes[] = $note;
        }
        return $notes;
    }

    /**
     * One walked subject against the watched set (once-S37): directories
     * take the same '/'-suffixed frame the walk's own verdicts use, so a
     * pattern that would have pruned the entry — had the walk reached the
     * exclude verdict — counts as a live pattern. A proven-live source
     * drops out; the census is a liveness witness, not a counter.
     */
    private function censusWalk(string $rel, bool $isDir): void
    {
        $subject = $isDir ? $rel . '/' : $rel;
        foreach ($this->watchedRecords as $pattern) {
            $source = $pattern['source'];
            if (isset($this->watchMatched[$source])) {
                continue;
            }
            if ($this->matchesPattern($pattern, $subject)) {
                $this->watchMatched[$source] = true;
            }
        }
    }

    /**
     * @return iterable<string> absolute file paths
     */
    public function scan(string $root): iterable
    {
        $root = rtrim($root, '/');
        if (!is_dir($root)) {
            // item 7.1: a named root file may be a tier file too — the
            // second gate is inert (false for all) unless armed.
            if (is_file($root) && ($this->looksLikePhp($root) || $this->looksLikeText($root))) {
                yield $root;
            } elseif (!is_file($root)) {
                // once-S37 (once-041): a path the user named that does not
                // exist used to return here empty-handed — silent on the
                // whole channel. Note it in the resolveDiffCohort dialect
                // ('scan root X does not exist'); the CLI admission gate
                // refuses the run outright, but every other consumer of
                // this class (serve, watch, tests) still gets the truth in
                // skipNotes(). A real non-PHP file stays a silent no-op —
                // it exists, the dialect simply declines it.
                $this->addSkipNote('scan root ' . $root . ' does not exist');
            }
            return;
        }

        try {
            $dirIterator = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS);
        } catch (\UnexpectedValueException $e) {
            // NN2: an unreadable scan ROOT previously died with an escaping
            // SPL UnexpectedValueException (exit 1 + usage dump). Skip it,
            // note it, move on to the next root.
            $this->noteSkip($root, $e->getMessage());
            return;
        }

        // shell-006: canonical form of the scan root. is_dir() above proved
        // the path resolves, so realpath() only fails on exotic races; in
        // that case the literal root is the best available yard-stick.
        $rootReal = realpath($root);
        if ($rootReal === false) {
            $rootReal = $root;
        }

        // once-S28: bind the per-directory overlay to THIS root before the
        // walk starts. Layers live in absolute form (ConfigLoader keys); the
        // walk decides in the root-relative frame, so each anchor is aliased
        // here. Accepted through both the literal root and its realpath form:
        // discovery may have keyed a directory through either.
        $this->activeLayers = $this->bindLayers($root, $rootReal);

        // W2a: .gitignore state for THIS scan() call only. The rule cache is
        // keyed by root-relative directory, so it is root-bound by
        // construction and fresh per call; consumers drain one root's
        // generator before starting the next (the ScanningStage per-root
        // loop), so no interleaving can observe a half-built cache.
        $gitignoreCache = [];
        $gitignoreStats = ['files' => 0, 'filesNoted' => false, 'misses' => 0];
        $gitignoreRootSkipped = 0;

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                $dirIterator,
                function (\RecursiveDirectoryIterator|\SplFileInfo|string $current) use ($root, $rootReal, &$gitignoreCache, &$gitignoreStats, &$gitignoreRootSkipped): bool {
                    // Psalm models every value the inner iterator may hand a
                    // RecursiveCallbackFilterIterator callback; only
                    // SplFileInfo carries paths/permissions, anything else
                    // passes through (identical posture to
                    // ConfigLoader::discoverPerDirectoryOverrides()).
                    if (!$current instanceof \SplFileInfo) {
                        return true;
                    }
                    $rel = $this->relpath($current->getPathname(), $root);
                    if ($rel === '') {
                        return true;
                    }
                    if ($this->watchSources !== []) {
                        // once-S37: every walked subject is a witness for
                        // the typo census — in the same frame the walk's
                        // own verdicts use (directories tested '/'-suffixed).
                        $this->walkedSubjects++;
                        $this->censusWalk($rel, $current->isDir());
                    }
                    $escape = $this->escapeReason($current, $rootReal);
                    if ($escape !== null) {
                        $this->noteSkipSymlink($current->getPathname(), $escape);
                        return false;
                    }
                    if ($current->isDir()) {
                        // once-S28: the verdict chains the root list with the
                        // per-directory layers when any apply. An excluded
                        // directory is STILL descended when a resetting layer
                        // sits at or below it — that config re-roots its
                        // subtree, and the per-entry verdicts inside need
                        // their chance to flip (re-root honesty).
                        if ($this->excludesWalkPath($rel, true) && !$this->hasResetAtOrBelow($rel)) {
                            return false;
                        }
                        if ($this->honorGitignore
                            && $this->gitignoredPath($rel, true, $root, $gitignoreCache, $gitignoreStats)) {
                            // W2a: verdict precedes descent on purpose — the
                            // directory's own .gitignore is never consulted
                            // for itself, and no rule below it gets a chance
                            // to revive it (git's documented limitation,
                            // mirrored here). Counted, not noted per path.
                            $gitignoreRootSkipped++;
                            return false;
                        }
                        // NN2: prune unreadable branches BEFORE descending,
                        // so the common chmod-000 case never even races the
                        // iterator. (Traversal needs the x bit; isReadable()
                        // covers it — same guard shape f326b06 uses.)
                        if (!$current->isReadable()) {
                            $this->noteSkip($current->getPathname(), 'directory is not readable');
                            return false;
                        }
                        return true;
                    }
                    if ($this->excludesWalkPath($rel, false)) {
                        return false;
                    }
                    if ($this->honorGitignore
                        && $this->gitignoredPath($rel, false, $root, $gitignoreCache, $gitignoreStats)) {
                        $gitignoreRootSkipped++;
                        return false;
                    }
                    // once-S37 (once-080 / QA-M8): the per-file mate of the
                    // directory guard above. An unreadable FILE used to
                    // survive the walk — and every consumer that then
                    // read or parsed it leaked a raw warning straight to
                    // stderr, past skipNotes() and past every scrub this
                    // class owns. Exclude verdict first: an excluded file
                    // was never a candidate and stays a silent no-prune.
                    if ($current->isFile() && !$current->isReadable()) {
                        $this->noteSkip($current->getPathname(), 'file is not readable');
                        return false;
                    }
                    return true;
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        try {
            foreach ($iter as $info) {
                /** @var \SplFileInfo $info */
                if (!$info->isFile()) {
                    continue;
                }
                // item 7.1: php OR (armed && tier extension) — disarmed,
                // looksLikeText() is false for every path and this arm is
                // the historical gate verbatim.
                if (!$this->looksLikePhp($info->getPathname()) && !$this->looksLikeText($info->getPathname())) {
                    continue;
                }
                yield $info->getPathname();
            }
        } catch (\UnexpectedValueException $e) {
            // Race: a directory passed the readable filter but went away or
            // lost permissions before the iterator descended into it. The
            // SPL iterator cannot resume, so this root ends here — paths
            // already yielded stay yielded. Note and move on.
            $this->noteSkip($root, 'scan truncated by a permission error mid-descent: ' . $e->getMessage());
        }

        if ($this->honorGitignore && $gitignoreRootSkipped > 0) {
            // W2a honest accounting: the walk's verdicts are silent per path
            // (a vendor-sized tree would mint a note per file); the drained
            // root instead contributes ONE aggregate line to the skipNotes
            // channel and the run-total accessor below.
            $this->gitignoreSkipped += $gitignoreRootSkipped;
            $this->addSkipNote(
                'gitignore: ' . $gitignoreRootSkipped . ' path'
                . ($gitignoreRootSkipped === 1 ? '' : 's')
                . ' excluded by .gitignore rules under scan root ' . $root
            );
        }
    }

    /**
     * Bind the overlay layers to the root about to be walked (once-S28):
     * keep the layers whose absolute directory sits under this root (in the
     * literal or realpath form of it) and re-anchor each survivor in the
     * root-relative frame the walk decides in. A layer belonging to a
     * different root can govern no entry of this one and is dropped for
     * this scan() — the stored layer list itself is never mutated.
     *
     * @return list<array{rel: string, patterns: list<array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool}>, reset: bool}>
     */
    private function bindLayers(string $root, string $rootReal): array
    {
        if ($this->layers === []) {
            return [];
        }
        // Each candidate root form accepts two anchor shapes: a config
        // sitting IN the scan root anchors to '' and governs the whole
        // tree; otherwise the '/'-suffixed prefix is required, so a
        // sibling /tmp/projX can never bind through /tmp/proj.
        $roots = [];
        foreach ([$root, $rootReal] as $candidate) {
            $trimmed = rtrim($candidate, '/');
            if ($trimmed !== '' && !isset($roots[$trimmed])) {
                $roots[$trimmed] = true;
            }
        }
        $active = [];
        foreach ($this->layers as $layer) {
            $rel = null;
            foreach (array_keys($roots) as $candidate) {
                if ($layer['dir'] === $candidate) {
                    $rel = '';
                    break;
                }
                if (str_starts_with($layer['dir'], $candidate . '/')) {
                    $rel = substr($layer['dir'], strlen($candidate) + 1);
                    break;
                }
            }
            if ($rel === null) {
                continue;
            }
            $active[] = [
                'rel' => $rel,
                'patterns' => $layer['patterns'],
                'reset' => $layer['reset'],
            ];
        }
        return $active; // already shallow→deep (sorted at construction)
    }

    /**
     * The walk's full exclusion verdict for one root-relative path
     * (once-S28): the root list plus the per-directory chain, shallow→deep.
     * One membership test covers both entry kinds — a layer applies when
     * the entry path starts with `anchor/`, which means strictly-below for
     * directories (the config at D governs D's contents, never D itself, so
     * a `.phpdup.json`'s own directory is never self-pruned) and at-or-below
     * for files (whose rel carries the basename). A resetting layer
     * discards the accumulated chain below it, including the root list; the
     * discard is one-way, exactly like Config::effectiveFor(). With no
     * layers bound the verdict is the plain root match, byte-for-byte the
     * pre-overlay walk.
     */
    private function excludesWalkPath(string $rel, bool $isDir): bool
    {
        if ($this->activeLayers === []) {
            return $this->isExcluded($isDir ? $rel . '/' : $rel);
        }
        $chain = [];
        $rootSuppressed = false;
        foreach ($this->activeLayers as $layer) {
            if ($layer['rel'] !== '' && !str_starts_with($rel, $layer['rel'] . '/')) {
                continue;
            }
            if ($layer['reset']) {
                $rootSuppressed = true;
                $chain = [];
            }
            $chain[] = $layer;
        }
        if (!$rootSuppressed && $this->isExcluded($isDir ? $rel . '/' : $rel)) {
            return true;
        }
        foreach ($chain as $layer) {
            // The subject for a layer's own patterns is the entry path in
            // THAT layer's frame — the dir-anchored reading the docs promise.
            $subject = $layer['rel'] === ''
                ? $rel
                : substr($rel, strlen($layer['rel']) + 1);
            if ($isDir) {
                $subject .= '/';
            }
            foreach ($layer['patterns'] as $pattern) {
                if ($this->matchesPattern($pattern, $subject)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Does a resetting overlay layer sit at or below this (excluded)
     * directory? Only then may the walk descend a pruned branch: a re-root
     * inside is the sole honest way to revive that subtree. A layer
     * anchored at the scan root ('' rel) never qualifies — it sits above
     * every walked directory, so a directory excluded despite it was
     * excluded by that layer's own surviving list, and only a strictly
     * deeper reset can contradict it.
     */
    private function hasResetAtOrBelow(string $relDir): bool
    {
        foreach ($this->activeLayers as $layer) {
            if (!$layer['reset']) {
                continue;
            }
            if ($layer['rel'] === $relDir || str_starts_with($layer['rel'], $relDir . '/')) {
                return true;
            }
        }
        return false;
    }

    private function noteSkip(string $path, string $reason): void
    {
        $this->addSkipNote("skipped unreadable path {$path} ({$reason})");
    }

    private function noteSkipSymlink(string $path, string $reason): void
    {
        $this->addSkipNote("skipped symlinked path {$path} ({$reason})");
    }

    private function addSkipNote(string $note): void
    {
        // shell-S34 (php-dup-finder-shell-025): a skip note embeds raw
        // filesystem bytes — an ESC sequence inside a crafted directory
        // name is terminal-injection material, and this leg has no Symfony
        // formatter to stand between the bytes and the terminal (the
        // plan's split: control-char scrubbing here, markup escaping at
        // the OutputInterface sinks). Scrub ONCE at the chokepoint, before
        // the note is stored, so skipNotes() and the stderr line can never
        // diverge and any future consumer inherits the same safety.
        $note = self::scrubControl($note);
        $this->skipNotes[] = $note;
        if ($this->warnOnSkip) {
            fwrite(STDERR, "phpdup: warning: {$note}\n");
        }
    }

    /**
     * Control bytes shown in addcslashes octal form (\033 for ESC, \012
     * for a note-forging newline), every other byte passed through
     * untouched — printable ASCII and UTF-8 multibyte sequences alike.
     */
    private static function scrubControl(string $text): string
    {
        return addcslashes($text, "\x00..\x1F\x7F");
    }

    /**
     * Why a symlinked entry must be dropped from the walk (shell-006), or
     * null when it may be traversed/yielded. Only links are canonicalized:
     * a non-link entry's literal path cannot leave $root, and every link on
     * the ancestor chain already passed this exact gate on the way down
     * (a link that resolves outside the root is pruned as a directory BEFORE
     * the iterator descends into it, so out-of-root subtrees are never
     * walked; loops through the root are pruned here, and other in-root
     * cycles terminate at the OS path limit).
     */
    private function escapeReason(\SplFileInfo $entry, string $rootReal): ?string
    {
        if (!$entry->isLink()) {
            return null;
        }
        $real = realpath($entry->getPathname());
        if ($real === false) {
            return 'target does not resolve';
        }
        if ($real === $rootReal) {
            return 'target re-enters the scan root';
        }
        // rtrim first: realpath() of the filesystem root is "/" itself, and
        // naively appending "/" would build a "//" yard-stick that falsely
        // refuses every in-root link under a "/"-style real root.
        $prefix = rtrim($rootReal, '/') . '/';
        if (!str_starts_with($real, $prefix)) {
            return 'target escapes the scan root';
        }
        return null;
    }

    private function looksLikePhp(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['php', 'php8', 'phtml', 'inc'], true);
    }

    /**
     * Item 7.1: the token tier's second gate — true exactly for files whose
     * extension is in the armed run's language table. Disarmed (empty list,
     * the default) this answers false for every path, which keeps the
     * scanner walk byte-identical to the pre-7.1 posture (the goldens'
     * foundation). The gate is admission only; whether a text file ever
     * becomes BLOCKS is the harvester's demand-gated decision in
     * PreprocessStage, and the tier never mints from a file whose extension
     * LangProfiles does not speak.
     */
    private function looksLikeText(string $path): bool
    {
        if ($this->textExtensions === []) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, $this->textExtensions, true);
    }

    /**
     * Root-relative form of one walked path (once-044: routed through
     * PathCanonical, so a backslash-joined Windows tree — including a
     * drive-prefixed root such as C:\proj — strips correctly and emits
     * canonical forward-slash segments for the exclude matcher). An
     * entry that IS the root still answers '' (the walk's own gate).
     */
    private function relpath(string $abs, string $root): string
    {
        if (PathCanonical::fold($abs) === PathCanonical::fold($root)) {
            return '';
        }
        return PathCanonical::underRoots($abs, [$root]);
    }

    public function isExcluded(string $relPath): bool
    {
        // once-044: the subject enters the matcher in canonical form,
        // and patterns compile canonical (see compilePattern), so both
        // sides of every verdict meet in the same separator dialect.
        $subject = PathCanonical::separators($relPath);
        foreach ($this->patterns as $pattern) {
            if ($this->matchesPattern($pattern, $subject)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does one compiled pattern exclude $relPath? Exact — never a silent
     * "no" from a resource limit (once-095).
     *
     * @param GitignoreMatch $pattern
     */
    private function matchesPattern(array $pattern, string $relPath): bool
    {
        if ($pattern['pcre']) {
            $result = preg_match($pattern['regex'], $relPath);
            if ($result === 1) {
                return true;
            }
            if ($result === 0) {
                return false;
            }
            // $result === false: a PCRE backtrack/JIT-stack limit fired. That
            // is NOT an answer — reading it as "no match" is the fail-OPEN
            // that sugar-ignore's incident turned one hostile line into a
            // silently-dead exclude rule. Fall through to the automaton,
            // which decides the same language without backtracking.
        }

        $verdict = $this->automatonMatch($pattern, $relPath);
        if ($verdict === null) {
            $this->noteUndecidable($pattern);
            return true; // fail CLOSED, loudly (see class docblock)
        }
        return $verdict;
    }

    /**
     * Whole-string match through the pattern's interned DFA: subset
     * construction over the token list, every (state, byte) transition
     * cached, so a fresh byte costs one step over the live NFA set and a
     * repeat costs one array lookup — no input can force backtracking work.
     * Returns null only when the path × pattern product exceeds
     * {@see MATCH_BUDGET}: refusing to evaluate, not answering "no".
     *
     * The DFA accepts exactly the language of the pattern's `#^…$#` regex,
     * quirks included: TOKEN_ANY does not cross "\n", and a final "\n" still
     * reaches PCRE's end anchor. The SEG state additionally loops on a bare
     * "/" because phpdup's `(?:[^/]*\/)*` allows empty segments (the
     * language-preservation clause of shell-030); sugar-ignore's
     * `(?:[^/]+/)*` group does not, which is the one place this port
     * deliberately diverges from the upstream Automaton.
     *
     * @param GitignoreMatch $pattern
     */
    private function automatonMatch(array $pattern, string $subject): ?bool
    {
        $tokens = $pattern['tokens'];
        $m = count($tokens);
        $n = strlen($subject);
        if ($n * ($m + 1) > self::MATCH_BUDGET) {
            return null;
        }

        // The DFA cache is keyed by the compiled regex, which FULLY
        // determines the byte language — class payloads are part of that
        // text, so two records sharing a regex share a (correct) automaton
        // even when their NFA token shapes differ.
        $key = $pattern['regex'];
        $automaton = $this->automata[$key] ?? null;
        if ($automaton === null || $automaton['sets'] === [] || count($automaton['sets']) > self::STATE_CAP) {
            // A cap rather than an unbounded cache: the subsets are at most
            // exponential in the pattern, and starting over loses only speed.
            $automaton = ['ids' => [], 'sets' => [], 'accepts' => [], 'next' => []];
            $start = [];
            self::closeEpsilon($start, 0, $tokens, $m);
            self::intern($automaton, $start, $m);
        }

        $walk = self::walkAutomaton($automaton, $subject, $tokens, $m, $pattern['classes'] ?? []);
        $this->automata[$key] = $walk[1];
        return $walk[0];
    }

    /**
     * Step one subject through the automaton, interning newly seen state
     * sets as the walk goes. The possibly-grown automaton travels back in
     * the return value: PHP hands arrays over by refcount (copy-on-write),
     * so the round trip is cheap, and it keeps the walk free of a
     * reference into a property — which psalm (321) refuses to analyze.
     *
     * @param array{ids: array<string, int>, sets: list<list<int>>, accepts: list<bool>, next: array<int, array<string, int>>} $automaton
     * @param list<array{0: int, 1: string}> $tokens
     * @param array<int, array{0: bool, 1: list<array{0: int, 1: int}>}> $classes position-keyed bracket-class members (gitignore rules only)
     * @return array{0: bool, 1: array{ids: array<string, int>, sets: list<list<int>>, accepts: list<bool>, next: array<int, array<string, int>>}}
     */
    private static function walkAutomaton(array $automaton, string $subject, array $tokens, int $m, array $classes = []): array
    {
        $n = strlen($subject);
        $state = 0;
        for ($i = 0; $i < $n; $i++) {
            $byte = $subject[$i];
            if ($i === $n - 1 && $byte === "\n" && $automaton['accepts'][$state]) {
                return [true, $automaton];
            }
            if (!isset($automaton['next'][$state][$byte])) {
                $live = self::stepAutomaton($automaton['sets'][$state], $byte, $tokens, $m, $classes);
                $automaton['next'][$state][$byte] = self::intern($automaton, $live, $m);
            }
            $state = $automaton['next'][$state][$byte];
            if ($automaton['sets'][$state] === []) {
                return [false, $automaton];
            }
        }

        return [$automaton['accepts'][$state], $automaton];
    }

    /**
     * The NFA states live after consuming $byte from the live set $states.
     *
     * NFA state k means "before token k" (k === m is accept), and m+1+k is
     * "inside a name of the SEG group at k" — a name may only close on the
     * "/" that completes an iteration, so it has no epsilon edge.
     *
     * @param list<int> $states
     * @param list<array{0: int, 1: string}> $tokens
     * @param array<int, array{0: bool, 1: list<array{0: int, 1: int}>}> $classes
     * @return array<int, true>
     */
    private static function stepAutomaton(array $states, string $byte, array $tokens, int $m, array $classes = []): array
    {
        $next = [];
        foreach ($states as $state) {
            if ($state === $m) {
                continue;
            }
            if ($state > $m) {
                // Inside one segment-name iteration: more name, or the "/"
                // that closes it and returns to the group's boundary.
                if ($byte === '/') {
                    self::closeEpsilon($next, $state - $m - 1, $tokens, $m);
                } else {
                    $next[$state] = true;
                }
                continue;
            }

            [$type, $payload] = $tokens[$state];
            if ($type === self::TOKEN_SEG) {
                if ($byte === '/') {
                    // Empty-name iteration: the group stays live at its own
                    // boundary (phpdup-dialect leg, see automatonMatch()).
                    self::closeEpsilon($next, $state, $tokens, $m);
                } else {
                    $next[$m + 1 + $state] = true;
                }
                continue;
            }
            if ($type === self::TOKEN_CLASS) {
                // Byte membership, git/wildmatch semantics; the map entry
                // mirrors the class's PCRE group (same parse, one truth),
                // and an empty list with neg=false is the never-class.
                [$negated, $ranges] = $classes[$state] ?? [false, []]; // absent map = never-match (conservative, mirrors the empty-range row)
                $ord = ord($byte);
                $inside = false;
                foreach ($ranges as [$lo, $hi]) {
                    if ($ord >= $lo && $ord <= $hi) {
                        $inside = true;
                        break;
                    }
                }
                if ($inside !== $negated) {
                    self::closeEpsilon($next, $state + 1, $tokens, $m);
                }
                continue;
            }
            $advance = match ($type) {
                self::TOKEN_LITERAL => $byte === $payload ? $state + 1 : null,
                self::TOKEN_ONE => $byte !== '/' ? $state + 1 : null,
                self::TOKEN_STAR => $byte !== '/' ? $state : null,
                self::TOKEN_ANY => $byte !== "\n" ? $state : null,
                default => null,
            };
            if ($advance !== null) {
                self::closeEpsilon($next, $advance, $tokens, $m);
            }
        }

        return $next;
    }

    /**
     * Add $state and every state reachable from it without consuming a byte:
     * a zero-length `*`, a zero-run `**`/ group, or a trailing `**` steps
     * straight to the next token.
     *
     * @param array<int, true> $set
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function closeEpsilon(array &$set, int $state, array $tokens, int $m): void
    {
        while (!isset($set[$state])) {
            $set[$state] = true;
            if ($state >= $m) {
                return;
            }
            $type = $tokens[$state][0];
            if ($type !== self::TOKEN_STAR && $type !== self::TOKEN_SEG && $type !== self::TOKEN_ANY) {
                return;
            }
            $state++;
        }
    }

    /**
     * The DFA state id for NFA set $set, creating it on first sight.
     *
     * @param array{ids: array<string, int>, sets: list<list<int>>, accepts: list<bool>, next: array<int, array<string, int>>} $automaton
     * @param array<int, true> $set
     */
    private static function intern(array &$automaton, array $set, int $m): int
    {
        $states = array_keys($set);
        sort($states);
        $key = implode(',', $states);
        if (isset($automaton['ids'][$key])) {
            return $automaton['ids'][$key];
        }

        $id = count($automaton['sets']);
        $automaton['ids'][$key] = $id;
        $automaton['sets'][] = $states;
        $automaton['accepts'][] = isset($set[$m]);

        return $id;
    }

    /**
     * Report an over-budget pattern once (regex-keyed, so duplicates in the
     * merged exclude list note together). The note text is the pattern as
     * sanitized for stderr: a hostile pattern must not steer the terminal
     * that reports it.
     *
     * @param GitignoreMatch $pattern
     */
    private function noteUndecidable(array $pattern): void
    {
        if (isset($this->undecidableNoted[$pattern['regex']])) {
            return;
        }
        $this->undecidableNoted[$pattern['regex']] = true;
        $this->addSkipNote(
            'exclude pattern ' . self::printablePattern($pattern['source'])
            . ' is too costly to evaluate; paths are treated as excluded by it'
        );
    }

    /**
     * $text with control bytes shown as "?", cut to
     * {@see NOTE_PATTERN_MAX_BYTES} on a UTF-8 boundary and marked with "…".
     */
    private static function printablePattern(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $text);
        if (strlen($text) <= self::NOTE_PATTERN_MAX_BYTES) {
            return $text;
        }

        $cut = self::NOTE_PATTERN_MAX_BYTES;
        while ($cut > 0 && (ord($text[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }

        return substr($text, 0, $cut) . '…';
    }

    /**
     * Translate a shell-style glob into a PCRE regex anchored on the path
     * relative to the scan root (directories are tested with a trailing "/").
     *
     * Dialect — segment-safe, matching the gitignore / bash-globstar
     * convention. No star form crosses a path separator accidentally
     * (note: in this table a `**` token is written with its backticks
     * closing before the following slash, so the comment itself never
     * contains a star-slash sequence):
     *
     *     `**`/         (?:[^/]*\/)*  zero or more WHOLE segments:
     *                                `**`/vendor/** matches `vendor/x.php` and
     *                                `a/b/vendor/c/x.php` but NEVER
     *                                `myvendor/x.php` or `notvendor/x.php`
     *     trailing `**`     .*       everything below, at any depth:
     *                                `vendor/**` → `vendor/` + `.*`
     *     any other `**`  [^/]*      `**` not sitting on a segment boundary
     *                                degenerates to one `*` (globstar convention):
     *                                `**.php` is a single-segment pattern — write
     *                                `**`/*.php for "any depth"
     *     `*`           [^/]*        within one segment (may be empty)
     *     `?`           [^/]         exactly one character within a segment
     *     `.` `/`       \.  /        literals; every other character is preg_quote'd
     *
     * This fixes the item-5.2 over-match: the old compiler emitted `.*` for
     * every `**`, so `**`/vendor/** crossed "/" and also wiped `myvendor/`.
     *
     * Adjacent globstar chains are COALESCED (shell-030): the segment form
     * `(?:[^/]*\/)*` matches zero or more whole segments, and that language
     * is closed under concatenation (a run of segments followed by another
     * run is still one run), so N adjacent groups accept exactly what one
     * group does. Emitting one group per `**`/ token instead handed PCRE a
     * nested-quantifier family: backtrack combinations grow like
     * C(segments+N-1, N-1), which on a 45-level path with a dozen chained
     * groups exhausts pcre.backtrack_limit — preg_match() then returns
     * silent false, the pattern stops matching anything, and `vendor`-style
     * protection evaporates without an error. One group per maximal run is
     * immune; the compiled form needs no raised limits.
     *
     * Coalescing adjacent runs is, however, only the first half of the
     * bound. Beyond it the compiler is BOUNDED (once-095, ported from
     * sugar-ignore after its ReDoS incident): every pattern also keeps a
     * token list, {@see backtracksSafely()} classifies whether PCRE may
     * retry its wildcard shape at all, hostile shapes are decided by the
     * backtrack-free automaton in {@see automatonMatch()}, and a PCRE limit
     * error anywhere falls through to that automaton instead of going
     * quiet. Compiled bytes are unchanged for every shape the old compiler
     * accepted — the dialect table above still governs, byte-identical,
     * including the coalescing this file has already pinned.
     *
     * This stays the canonical compile entry: the constructor routes every
     * pattern through it, exactly as the shell-S21 test seam does.
     */
    private function globToRegex(string $glob): string
    {
        return self::regexFor(self::tokenizeGlob($glob));
    }

    /**
     * Split one exclude glob into matcher tokens — the single source of
     * truth both for the regex ({@see regexFor()}) and for the automaton
     * ({@see automatonMatch()}), so the two matchers cannot drift.
     *
     * Star-run folding (`***` behaves as `**`), the trailing-`**`/
     * segment-boundary/ mid-segment trichotomy, and the one-group-per-
     * maximal-run coalescing mirror the legacy emit branch for branch;
     * the adjacency test here is collision-proof for the same reason the
     * legacy suffix test was (shell-030): only a TOKEN_SEG emit can put
     * that exact text on the end, and every literal byte goes through
     * preg_quote.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function tokenizeGlob(string $glob): array
    {
        $glob = ltrim($glob, '/');
        $tokens = [];
        $i = 0;
        $len = strlen($glob);
        while ($i < $len) {
            $c = $glob[$i];
            if ($c === '*') {
                if ($i + 1 < $len && $glob[$i + 1] === '*') {
                    // Collapse the whole '**'-style run (consumes any extra
                    // stars; '***' behaves as '**').
                    while ($i < $len && $glob[$i] === '*') {
                        $i++;
                    }
                    if ($i >= $len) {
                        $tokens[] = [self::TOKEN_ANY, ''];    // trailing ** → everything below
                    } elseif ($glob[$i] === '/') {
                        $i++; // consume the boundary slash
                        // shell-030: coalesce an adjacent '**/' chain into ONE
                        // segment group (token-adjacency form of the legacy
                        // regex-suffix test — see the docblock above).
                        $lastType = $tokens === [] ? null : $tokens[count($tokens) - 1][0];
                        if ($lastType !== self::TOKEN_SEG) {
                            $tokens[] = [self::TOKEN_SEG, ''];
                        }
                    } else {
                        $tokens[] = [self::TOKEN_STAR, ''];   // ** mid-segment → one '*'
                    }
                } else {
                    $tokens[] = [self::TOKEN_STAR, ''];
                    $i++;
                }
            } elseif ($c === '?') {
                $tokens[] = [self::TOKEN_ONE, ''];
                $i++;
            } else {
                // Every other byte — '.', '/', and anything preg_quote'd —
                // is a literal. regexFor() reproduces the legacy per-byte
                // emits exactly ('\.' and the bare '/').
                $tokens[] = [self::TOKEN_LITERAL, $c];
                $i++;
            }
        }
        return $tokens;
    }

    /**
     * The anchored regex for $tokens, byte-identical to what the legacy
     * per-character compiler emitted: '/' stays a bare slash and every
     * other literal byte goes through preg_quote with the '#' delimiter
     * (which renders '.' as '\.', exactly as the legacy '.' branch did).
     *
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function regexFor(array $tokens): string
    {
        $out = '';
        foreach ($tokens as [$type, $payload]) {
            $out .= match ($type) {
                self::TOKEN_LITERAL => $payload === '/' ? '/' : preg_quote($payload, '#'),
                self::TOKEN_ONE => '[^/]',
                self::TOKEN_STAR => '[^/]*',
                self::TOKEN_SEG => '(?:[^/]*/)*',
                self::TOKEN_ANY => '.*',
                // A gitignore character class carries its prebuilt PCRE group
                // as the payload ({@see tokenizeGitignore()}); the glob
                // dialect never mints one, so existing regexes are untouched.
                self::TOKEN_CLASS => $payload,
                default => throw new \LogicException('unknown glob token kind ' . $type),
            };
        }

        return '#^' . $out . '$#';
    }

    /**
     * Can PCRE run this pattern without catastrophic backtracking?
     *
     * Backtracking explodes when unbounded wildcards can trade the same
     * text between them: `[^/]*a[^/]*a[^/]*c` tries every split of the
     * path among its stars, and chained `**`/ groups split the segment
     * sequence the same way — measured on the current compiler at 42 ms
     * and 24 ms per path (then a silent false) for the 14-wildcard and
     * 10-group shapes respectively. Allowed: at most two wildcards, at
     * most one of which crosses `/` (TOKEN_SEG or trailing `**`). That
     * keeps every real-world shape — `*.php`, `vendor/**`, `**`/*.php,
     * `**`/build — on the fast path with a worst case PCRE's limits
     * bound; anything more goes straight to the exact automaton rather
     * than paying the limit first on every path (sugar-ignore's
     * classification, ported).
     *
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function backtracksSafely(array $tokens): bool
    {
        $wildcards = 0;
        $crossing = 0;
        foreach ($tokens as [$type]) {
            if ($type === self::TOKEN_STAR) {
                $wildcards++;
            } elseif ($type === self::TOKEN_SEG || $type === self::TOKEN_ANY) {
                $wildcards++;
                $crossing++;
            }
        }

        return $wildcards <= 2 && $crossing <= 1;
    }

    // ── once-S34 — authoritative --diff-base cohort for named roots ───────
    //    (php-dup-finder-once-038: named roots, repo-relative git, unicode,
    //    empty cohort; the consumer entry lives in Cli\Command).

    /**
     * Resolve the changed-file cohort of `git diff <ref>..HEAD` for the
     * configured scan roots — the named-root precision leg once-S34 added
     * on top of shell-S23's argv-safe per-root probing posture.
     *
     * The stage-level probe joins every repo-relative git line under the
     * scan root, which is only correct when a root IS the repo toplevel;
     * a subdir or file root silently collected nothing and the pipeline
     * fell back to a FULL unscoped scan (QA-H4). This resolver instead
     * asks git for the real toplevel, maps each changed path onto disk
     * from there, and keeps only paths lying under one of the named
     * roots — repo-relative git, named-root-correct mapping.
     *
     * Honesty guarantees, each carrying over a shell-040 / XP-03 law:
     *  • argv-only invocation (proc_open array form: no shell layer;
     *    `--end-of-options` pins the rev position so an option-shaped ref
     *    is refused by git instead of being parsed as an option);
     *  • git stderr never enters the filename stream (scratch file);
     *  • `-c core.quotePath=false` keeps non-ASCII changed paths as the
     *    raw bytes that exist on disk, never octal-escaped quotes that
     *    could not match (the XP-03 under-inclusive cohort);
     *  • paths changed in range but absent now (deleted or renamed away
     *    under a root) are reported in `unmatched`, never guessed at.
     *
     * Cohort paths are realpaths — the same key form ScanningStage/
     * ClusterStage already match, so scan roots should be given in the
     * form that resolves onto the scanned files (the pre-once-S34
     * posture; a literal-yet-canonical root keeps working byte-exact).
     *
     * A git failure on ANY root aborts the whole probe: `error` names the
     * directory git could not satisfy, and `paths`/`unmatched` come back
     * empty (fail fast — the CLI renders the exit-2 line; there is no
     * partial cohort to distrust).
     *
     * @param list<string> $roots    scan roots as configured (dir or file)
     * @param string       $diffBase git rev for the <diffBase>..HEAD range
     * @return array{error: ?string, paths: list<string>, unmatched: list<string>}
     */
    public static function resolveDiffCohort(array $roots, string $diffBase): array
    {
        $revSpec = $diffBase . '..HEAD';

        /** @var array<string, true> $cohort realpath-keyed; dedups across roots */
        $cohort = [];
        /** @var list<string> $unmatched changed paths (git-relative) under a root that are no longer on disk */
        $unmatched = [];

        foreach ($roots as $root) {
            $isDirRoot = is_dir($root);
            $rootReal  = realpath($root);
            if ($rootReal === false) {
                // A root that does not exist cannot host changed files.
                // ScanningStage already skips such roots during discovery;
                // here the fact is surfaced, never silently cohort-emptying.
                $unmatched[] = 'scan root ' . $root . ' does not exist';
                continue;
            }
            $probeDir = $isDirRoot ? $rootReal : dirname($rootReal);

            [$topOut, $topErr, $topCode] = self::runGitProcess([
                'git', '-C', $probeDir, 'rev-parse', '--show-toplevel',
            ]);
            $toplevel = rtrim($topOut, "\r\n");
            if ($topCode !== 0 || $toplevel === '') {
                return self::diffProbeError($probeDir, $topErr, $topCode, 'git rev-parse --show-toplevel');
            }
            $topReal = realpath($toplevel);
            if ($topReal === false) {
                return self::diffProbeError($probeDir, 'git printed a repository root that does not exist: ' . $toplevel, 0, 'git rev-parse --show-toplevel');
            }

            [$diffOut, $diffErr, $diffCode] = self::runGitProcess([
                'git', '-C', $topReal,
                '-c', 'core.quotePath=false',
                'diff', '--name-only', '--no-ext-diff',
                '--end-of-options', $revSpec, '--',
            ]);
            if ($diffCode !== 0) {
                return self::diffProbeError($probeDir, $diffErr, $diffCode, 'git diff');
            }

            foreach (explode("\n", $diffOut) as $line) {
                $line = rtrim($line, "\r");
                if ($line === '') {
                    continue;
                }
                // git output is repo-root relative and never absolute;
                // map it onto disk, then keep only what lies under THIS
                // root. A changed path outside every named root is a
                // scope decision, not a miss — it is not "unmatched".
                $abs = $topReal . '/' . $line;
                $inside = $isDirRoot
                    ? str_starts_with($abs, $rootReal . '/')
                    : $abs === $rootReal;
                if (!$inside) {
                    continue;
                }
                $real = realpath($abs);
                if ($real === false) {
                    // Changed in range, lies under a named root, but is
                    // gone now (deleted or renamed away by a later commit
                    // in the range). Report it, do not pretend.
                    $unmatched[] = $line;
                    continue;
                }
                $cohort[$real] = true;
            }
        }

        $paths = array_keys($cohort);
        sort($paths);

        return ['error' => null, 'paths' => $paths, 'unmatched' => $unmatched];
    }

    /**
     * The one abort shape of {@see resolveDiffCohort}: the `--diff-base`
     * git probe failed at a directory the user named. The wording keeps
     * shell-S23's substrings intact ('--diff-base git diff failed in') so
     * command-level assertions about the refusal class stay stable across
     * the move; the concrete git verb distinguishes the two stages.
     *
     * @return array{error: string, paths: list<string>, unmatched: list<string>}
     */
    private static function diffProbeError(string $probeDir, string $stderr, int $code, string $verb): array
    {
        $reason = trim($stderr) !== '' ? trim($stderr) : ($verb . ' exited with status ' . $code);

        return [
            'error'     => 'phpdup: --diff-base git diff failed in ' . $probeDir . ': ' . $reason,
            'paths'     => [],
            'unmatched' => [],
        ];
    }

    /**
     * Shell-free git invocation — the proc_open array form shell-S23
     * introduced in ScanningStage, so FileScanner owns its probe
     * end-to-end: stdout comes back in full, stderr lands in a scratch
     * file and can neither poison the filename stream nor deadlock a
     * huge stdout against a full stderr pipe.
     *
     * @param list<string> $argv
     * @return array{0: string, 1: string, 2: int} [stdout, stderr, exit code]
     */
    private static function runGitProcess(array $argv): array
    {
        $errPath = tempnam(sys_get_temp_dir(), 'phpdup-git-stderr-');
        if ($errPath === false) {
            return ['', 'could not create a scratch file for git stderr', 127];
        }

        $pipes = [];
        $proc  = proc_open($argv, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $errPath, 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            $stderr = 'could not launch ' . $argv[0];
            @unlink($errPath);
            return ['', $stderr, 127];
        }
        if (!isset($pipes[1]) || !is_resource($pipes[1])) {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($errPath);
            return ['', 'git stdout pipe unavailable', 127];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code   = proc_close($proc);
        $stderr = (string) @file_get_contents($errPath);
        @unlink($errPath);

        return [$stdout, $stderr, $code];
    }
}
