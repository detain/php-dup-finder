<?php
declare(strict_types=1);

namespace Phpdup\Scanning;

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
 */
final class FileScanner
{
    /** @var list<string> */
    private array $excludeRegexes;

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
        $this->excludeRegexes = array_map([$this, 'globToRegex'], $excludeGlobs);
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

    private function relpath(string $abs, string $root): string
    {
        if (str_starts_with($abs, $root . '/')) {
            return substr($abs, strlen($root) + 1);
        }
        if ($abs === $root) {
            return '';
        }
        return $abs;
    }

    public function isExcluded(string $relPath): bool
    {
        foreach ($this->excludeRegexes as $re) {
            if (preg_match($re, $relPath) === 1) {
                return true;
            }
        }
        return false;
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
     */
    private function globToRegex(string $glob): string
    {
        $glob = ltrim($glob, '/');
        $re = '';
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
                        $re .= '.*';            // trailing ** → everything below
                    } elseif ($glob[$i] === '/') {
                        $i++;                   // consume the boundary slash
                        // shell-030: coalesce an adjacent '**/' chain into ONE
                        // segment group. Only the group emitted by this branch
                        // can end $re with that exact text (literal chars go
                        // through preg_quote, which back-escapes ( ? : [ ] * )
                        // and /), so this suffix test is collision-proof.
                        $group = '(?:[^/]*/)*'; // zero or more whole segments
                        if (!str_ends_with($re, $group)) {
                            $re .= $group;
                        }
                    } else {
                        $re .= '[^/]*';         // ** mid-segment → one '*'
                    }
                } else {
                    $re .= '[^/]*';
                    $i++;
                }
            } elseif ($c === '?') {
                $re .= '[^/]';
                $i++;
            } elseif ($c === '.') {
                $re .= '\.';
                $i++;
            } elseif ($c === '/') {
                $re .= '/';
                $i++;
            } else {
                $re .= preg_quote($c, '#');
                $i++;
            }
        }
        return '#^' . $re . '$#';
    }
}
