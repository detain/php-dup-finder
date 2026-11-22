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
 */
final class FileScanner
{
    /** Token kinds of the compiled glob dialect (see {@see globToRegex()}). */
    private const TOKEN_LITERAL = 0; // a literal byte
    private const TOKEN_ONE = 1;     // [^/]
    private const TOKEN_STAR = 2;    // [^/]*  (within one segment)
    private const TOKEN_SEG = 3;     // (?:[^/]*/)*  (zero or more whole segments)
    private const TOKEN_ANY = 4;     // .*  (trailing **, crosses segments)

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
     * @param list<string> $excludeGlobs
     * @param bool         $warnOnSkip emit a stderr warning line per skipped
     *                                 path recorded in skipNotes() (in
     *                                 addition to the note itself).
     */
    public function __construct(array $excludeGlobs, private readonly bool $warnOnSkip = true)
    {
        $this->patterns = array_map([$this, 'compilePattern'], $excludeGlobs);
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
     * @return iterable<string> absolute file paths
     */
    public function scan(string $root): iterable
    {
        $root = rtrim($root, '/');
        if (!is_dir($root)) {
            if (is_file($root) && $this->looksLikePhp($root)) {
                yield $root;
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

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                $dirIterator,
                function (\RecursiveDirectoryIterator|\SplFileInfo|string $current) use ($root, $rootReal): bool {
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
                    $escape = $this->escapeReason($current, $rootReal);
                    if ($escape !== null) {
                        $this->noteSkipSymlink($current->getPathname(), $escape);
                        return false;
                    }
                    if ($current->isDir()) {
                        if ($this->isExcluded($rel . '/')) {
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
                    return !$this->isExcluded($rel);
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
                if (!$this->looksLikePhp($info->getPathname())) {
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
        $this->skipNotes[] = $note;
        if ($this->warnOnSkip) {
            fwrite(STDERR, "phpdup: warning: {$note}\n");
        }
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
     * @param array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool} $pattern
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
     * @param array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool} $pattern
     */
    private function automatonMatch(array $pattern, string $subject): ?bool
    {
        $tokens = $pattern['tokens'];
        $m = count($tokens);
        $n = strlen($subject);
        if ($n * ($m + 1) > self::MATCH_BUDGET) {
            return null;
        }

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

        $walk = self::walkAutomaton($automaton, $subject, $tokens, $m);
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
     * @return array{0: bool, 1: array{ids: array<string, int>, sets: list<list<int>>, accepts: list<bool>, next: array<int, array<string, int>>}}
     */
    private static function walkAutomaton(array $automaton, string $subject, array $tokens, int $m): array
    {
        $n = strlen($subject);
        $state = 0;
        for ($i = 0; $i < $n; $i++) {
            $byte = $subject[$i];
            if ($i === $n - 1 && $byte === "\n" && $automaton['accepts'][$state]) {
                return [true, $automaton];
            }
            if (!isset($automaton['next'][$state][$byte])) {
                $live = self::stepAutomaton($automaton['sets'][$state], $byte, $tokens, $m);
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
     * @return array<int, true>
     */
    private static function stepAutomaton(array $states, string $byte, array $tokens, int $m): array
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
     * @param array{source: string, regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool} $pattern
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
