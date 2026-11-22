<?php
declare(strict_types=1);

namespace Phpdup\Persistence;

/**
 * Builds explicit class allow-lists for {@see unserialize()} on the
 * disk caches and the worker wire, and owns the single shared
 * blocked-class screen every restored graph passes.
 *
 * Why explicit lists? `unserialize($blob, ['allowed_classes' => true])`
 * accepts *any* class the autoloader can find — that is the textbook
 * PHP-object-injection footgun, and SAST tools (Codacy, Psalm taint
 * mode, etc.) flag the literal `true` on sight.
 *
 * The phpdup caches store either:
 *   - PhpParser AST nodes (AstCache); or
 *   - phpdup's own value-objects (ClusterCache, IndexStore: Cluster /
 *     Block / Hole / LineRange) which themselves carry PhpParser nodes.
 *
 * We restrict deserialization to those classes only. Any cache entry
 * containing an unexpected class name lands as `__PHP_Incomplete_Class`,
 * which our load paths treat as a cache miss.
 *
 * **engine-S39 curation rule (php-dup-finder-engine-058):** the
 * PhpParser family is admitted by property, not by namespace. A
 * `PhpParser\` class is allowed only when it is a subclass of
 * {@see \PhpParser\Node}, or when it appears in
 * {@see self::BLOB_VALUE_CLASSES} — the enumerated non-Node value
 * objects honest blobs really carry. Before this rule the entire
 * `PhpParser\` prefix was auto-trusted (270 classes, 75 of them NOT
 * nodes: parser machinery, visitors, pretty-printers, and the
 * `PhpParser\Error` / `PhpParser\ConstExprEvaluationException`
 * Exception subclasses arriving with the inherited `__wakeup` hook).
 * Handing those to `unserialize()` on hostile bytes is the gadget
 * entry point the prefix admission existed to avoid. The companion test
 * {@see \Phpdup\Tests\Unit\Persistence\SerializedClassAllowListTest::testNoAllowedClassCarriesUnserializeHooks}
 * fails the build if any admitted class ever grows a
 * `__destruct`/`__wakeup`/`__unserialize` hook.
 *
 * **shell-S11 (php-dup-finder-shell-011, shell-034):** the blocked-class
 * machinery lives HERE as one shared home with three screens.
 * {@see self::firstUnallowlistedClassHeader()} is the WIRE hot-path gate:
 * a single C-speed pass names the first class header the list cannot
 * vouch for, and a frame it calls clean provably cannot decode an
 * `__PHP_Incomplete_Class` — so it skips the graph walk the pre-S11
 * decode ran over every frame (2.13x the cost it gated).
 * {@see self::findIncompleteClass()} stays the authoritative graph walk
 * (the moved shell-S19 screen) for every frame the gate cannot clear.
 * {@see self::findBlockedClassInPayload()} is the stricter byte-grammar
 * screen over raw payload bytes — kept as the tested store-side utility
 * shell-S39's allow-list leg consumes. The allow-list itself is
 * memoized end to end: {@see self::blockCacheClasses()} now returns the
 * same list every call.
 */
final class SerializedClassAllowList
{
    /**
     * The non-Node PhpParser classes that legitimately appear inside a
     * real cache blob or worker frame. This is the sanctioned
     * widening of the Node-subclass rule — each entry names the blob
     * family that needs it, and each stays a scalar-only leaf that
     * {@see \Phpdup\Parsing\CacheBlob}'s screens walk. Adding a class
     * here requires the same evidence, and it must survive the
     * unserialize-hook test above.
     *
     * - `Token` — the TokenCache element class (engine-018); its blob
     *   is exactly a list of these.
     * - `Comment` and its `Comment\Doc` subclass — the `comments`
     *   attribute leaf php-parser's CommentAnnotatingVisitor attaches
     *   at the end of every `parse()` of commented source
     *   (engine-S23a r2): without them every honest commented file
     *   would decode to an incomplete class and become a permanent
     *   cache miss (and every `--workers` run of commented code would
     *   trip WorkerPool's blocked-class fail-loud).
     *
     * Measured over the whole `src/` corpus, the token stream and the
     * wire shapes: no other non-Node class ever appears.
     *
     * @var list<class-string>
     */
    public const BLOB_VALUE_CLASSES = [
        \PhpParser\Token::class,
        \PhpParser\Comment::class,
        \PhpParser\Comment\Doc::class,
    ];

    /**
     * Returned by {@see self::findBlockedClassInPayload()} when the byte
     * walk cannot frame the payload inside the tag grammar
     * {@see self::walkPayload()} implements (truncated header, exotic tag
     * like `S:`/`u:` that native unserialize may still accept). That is
     * NOT a clean verdict: the caller must fall back to
     * {@see self::findIncompleteClass()} on the decoded graph. The
     * sentinel contains characters no PHP class name may, so it can
     * never be confused with a real blocked-class name.
     */
    public const SCAN_UNDECIDABLE = '<undecidable: payload outside the byte grammar>';

    /**
     * Returned by {@see self::findIncompleteClass()} when the bounded
     * walk ran out of edge budget before it covered the graph — NOT a
     * clean verdict. The shell-S19 stores translate it into an ordinary
     * cache miss (recompute, never serve unchecked data); WorkerPool's
     * decode guard fails loud on it (a wire frame that cannot be
     * screened is not a frame to yield). The VALUE is identical to
     * {@see IndexStore::SCAN_UNRESOLVED} by contract — the S19 call
     * sites compare the string, so both spellings must agree.
     */
    public const SCAN_UNRESOLVED = '<unresolved: bounded shape walk exceeded>';

    /**
     * Edge budget for the shared shape walk (the shell-S19 constant,
     * canonically homed here since shell-S11; IndexStore keeps its
     * private alias so existing call sites and pins read unchanged).
     */
    public const SHAPE_SCAN_EDGES = 5_000_000;

    /** @var list<class-string>|null Memoized parser-class enumeration. */
    private static ?array $parserClasses = null;

    /**
     * shell-034: the parser ∪ value-object union is immutable per
     * process, so the merge runs exactly once, next to the
     * {@see self::$parserClasses} memo it consumes. IndexStore re-called
     * this per cached file (2,188 times in PERF's 2,500-file run) and
     * every call paid an array_merge + array_unique over ~200 classes.
     *
     * @var list<class-string>|null
     */
    private static ?array $blockCacheClasses = null;

    /**
     * One-entry memo of the flipped allow-list (name => true) consumed
     * by the byte screen. The hot caller re-passes the SAME memoized
     * {@see self::blockCacheClasses()} array every frame, so the
     * `===` re-check short-circuits on array-pointer identity and the
     * flip is built once per process per list.
     *
     * @var list<class-string>|null
     */
    private static ?array $flipSource = null;

    /** @var array<string,true>|null */
    private static ?array $flip = null;

    /**
     * Identity pair for the memoized set of $allowList names the
     * autoloader cannot resolve (shell-011). A header naming such a
     * class arrives as __PHP_Incomplete_Class EVEN THOUGH the name is
     * on the list, so the gate may only call a frame clean when no
     * header spells one of these. The wire re-passes the same memoized
     * list every frame, so the `===` check builds this once per list.
     *
     * @var list<class-string>|null
     */
    private static ?array $unresolvableSource = null;

    /** @var array<string,true>|null */
    private static ?array $unresolvableNames = null;

    /**
     * The phpdup value-object classes that can appear inside a cached
     * cluster set or per-file block list.
     *
     * @return list<class-string>
     */
    public static function blockObjectClasses(): array
    {
        return [
            \Phpdup\Clustering\Cluster::class,
            \Phpdup\Extraction\Block::class,
            \Phpdup\Refactor\Hole::class,
            \Phpdup\Util\LineRange::class,
        ];
    }

    /**
     * The AST-node family admitted to decode: every autoloadable
     * `PhpParser\` class that is a subclass of {@see \PhpParser\Node},
     * plus {@see self::BLOB_VALUE_CLASSES}.
     *
     * Candidate *names* are discovered from composer's class map
     * (falling back to a package-directory walk when the tree ships
     * without one); membership is decided by the Node property — the
     * prefix alone never admits a class (engine-S39).
     *
     * Memoized per-process. The predicate consults triggers the
     * one-time autoload of the library (~300 class files, most already
     * live in any process that reaches this path) and keeps the list
     * deterministic instead of load-order dependent.
     *
     * @return list<class-string>
     */
    public static function parserClasses(): array
    {
        if (self::$parserClasses !== null) {
            return self::$parserClasses;
        }
        $out = [];
        foreach (self::discoverPhpParserClassNames() as $fqn) {
            // is_subclass_of() consults the autoloader, so this both
            // narrows the candidate name and keeps the predicate
            // deterministic.
            if (is_subclass_of($fqn, \PhpParser\Node::class)) {
                $out[] = $fqn;
            }
        }
        // Admitted unconditionally: they are the documented blob
        // value-objects, and a cache must never silently void the
        // token stream or the comments leaf (see the const's evidence
        // list).
        foreach (self::BLOB_VALUE_CLASSES as $extra) {
            $out[] = $extra;
        }
        $out = array_values(array_unique($out));
        self::$parserClasses = $out;
        return $out;
    }

    /**
     * The combined allow-list used when reading a cached AST blob or a
     * worker frame: parser node classes (plus the enumerated value
     * objects) and phpdup's own value-objects, so a Block with an
     * embedded canonical AST round-trips intact.
     *
     * Contract stable since the caches were sealed (shell-S18 era):
     * AstCache/TokenCache consume {@see self::parserClasses()}, while
     * IndexStore, ClusterCache and WorkerPool's fork transport consume
     * this union.
     *
     * Memoized since shell-S11 (php-dup-finder-shell-034): every call
     * returns the identical list — callers must treat it as read-only
     * (the same rule the enumeration already carried for
     * {@see self::parserClasses()}).
     *
     * @return list<class-string>
     */
    public static function blockCacheClasses(): array
    {
        if (self::$blockCacheClasses !== null) {
            return self::$blockCacheClasses;
        }
        self::$blockCacheClasses = array_values(array_unique(array_merge(
            self::blockObjectClasses(),
            self::parserClasses(),
        )));
        return self::$blockCacheClasses;
    }

    /**
     * The strict blocked-class screen over serialized bytes (shell-S11,
     * php-dup-finder-shell-011): the store-side utility — S19 loads may
     * pre-screen blobs and the S39 allow-list leg consumes it by stable
     * signature. (The WIRE path uses the cheaper
     * {@see self::firstUnallowlistedClassHeader()} gate instead; the
     * per-token framing this walk does cannot beat C-speed unserialize
     * over the same bytes — measured 10x slower than the graph walk it
     * was meant to replace, so it never ships on the hot decode.)
     *
     * The old wire form was a recursive walk of the DECODED graph (every
     * Block, every AST node, a fresh `get_object_vars()` array per
     * object) — measured at 2.13x the cost of the unserialize it gates
     * (447 ms scan vs 210 ms decode on identical pair-score streams,
     * ~0.23 ms/row ≈ 10 s per mid-corpus). The byte form is a linear
     * framing pass over the payload's own bytes: PHP's serialize
     * grammar length-prefixes every string, so the walk can skip string
     * bodies wholesale (the corpus bulk) instead of visiting them, and
     * every `O:`/`C:` class name is its own length-prefixed quoted
     * token the walk checks against the flipped allow-list BEFORE the
     * graph ever exists (`E:` enum tokens are framed-checked and
     * skipped — PHP restores real enums regardless of the list and
     * rejects whole payloads on missing ones, so the graph screen never
     * saw an incomplete through one; see the E: branch).
     *
     * Why this is exact, not heuristic:
     *  - a class absent from the list decodes to `__PHP_Incomplete_Class`
     *    under exactly the name the payload spells — the byte check and
     *    the graph check interrogate the same fact;
     *  - serialized-looking text inside string DATA (e.g. a comment or
     *    scalar literal carrying `O:8:"Evil":...`) is skipped by the
     *    enclosing `s:<len>:"` length prefix — no false refusal;
     *  - trailing bytes after the root value never decode into the
     *    graph (PHP's unserialize ignores them, the framing miss is
     *    CacheBlob's job) and the walk stops with the root value, so it
     *    never invents verdicts from dead tail either.
     *  - a name the walk reads is admitted only when the list spells it
     *    identically — the check is byte-exact, where unserialize's own
     *    match is case-insensitive, so a case-shuffled smuggle REFUSES
     *    loud instead of decoding. Strictly never weaker than the graph
     *    walk, which moreover could not see poison parked in protected
     *    or private slots at all (get_object_vars from foreign scope
     *    exposes public only).
     *
     * Undecidable ≠ clean: a payload outside the implemented grammar
     * (truncated mid-header, an exotic tag PHP's decoder may still
     * accept) returns {@see self::SCAN_UNDECIDABLE}, and the caller
     * falls back to {@see self::findIncompleteClass()} on the decoded
     * graph. Legitimate frames — the only shape a forked child's
     * serialize() emits — always frame cleanly.
     *
     * @param list<class-string> $allowList
     * @return string|null|self::SCAN_UNDECIDABLE the blocked class
     *         (O:/C:) name; null when every object the payload carries
     *         is admitted; the sentinel when the bytes cannot be framed.
     */
    public static function findBlockedClassInPayload(string $payload, array $allowList): ?string
    {
        return self::walkPayload($payload, self::flipFor($allowList));
    }

    /**
     * The wire's cheap pre-decode gate (shell-S11, php-dup-finder-
     * shell-011): names the FIRST class header in the serialized bytes
     * the allow-list cannot vouch for, or null when every header is
     * provably decodable into a REAL object.
     *
     * PHP's serializer spells a literal `O:<len>:"<name>":`,
     * `C:<len>:"<name>":` or `E:<len>:"<name>":` header for EVERY
     * object instance in a stream — `r:`/`R:` references always point
     * back at one of those headers, and no other tag manufactures an
     * object — so the header set over the bytes is complete. Every
     * `__PHP_Incomplete_Class` in a decoded graph therefore originates
     * from exactly one of these headers, which means a frame whose
     * headers all name classes that are ON the list AND resolvable
     * cannot contain an incomplete anywhere: the graph walk exists to
     * find that one marker, so skipping it for such frames is verdict-
     * preserving by construction, not a heuristic.
     *
     * Anything the gate cannot vouch for returns a name (routing the
     * frame through {@see self::findIncompleteClass()} on the decoded
     * graph — the baseline chain, unchanged):
     *  - a header name off the list (real poison; also serialized-
     *    lookalike bait, which the walk then clears exactly as it
     *    always did);
     *  - a name on the list the autoloader cannot find — an allowed
     *    but missing class decodes to an incomplete just as a
     *    disallowed one does;
     *  - a header whose declared length disagrees with the captured
     *    bytes — a hand-forged name carrying a quote or NUL, which
     *    PHP (parsing by length, not by quote) would decode to a
     *    DIFFERENT name than the capture;
     *  - the `E:` enum form, whose name is `"<class>:<case>"` and so
     *    never matches a plain class name on the list (legitimate
     *    worker frames never contain one — a poisoned or exotic
     *    payload takes the walk and gets its baseline verdict);
     *  - a payload the scan could not run at all (PCRE resource
     *    failure) — reported as {@see self::SCAN_UNDECIDABLE}, never
     *    as clean.
     *
     * Case is compared byte-exactly through the memoized flip,
     * mirroring the byte screen's posture: a case-shuffled smuggle
     * routes to the walk rather than being waved through.
     *
     * @param list<class-string> $allowList
     * @return string|null the suspect header name (or the SCAN_UNDECIDABLE
     *                     sentinel), null when the frame provably decodes
     *                     with no incomplete class in it.
     */
    public static function firstUnallowlistedClassHeader(string $payload, array $allowList): ?string
    {
        $count = preg_match_all('/[OCE]:(\d+):"([^"]*)"/', $payload, $m);
        if ($count === false) {
            return self::SCAN_UNDECIDABLE;
        }
        if ($count === 0) {
            return null; // no class header exists — nothing can decode as incomplete
        }
        $flip         = self::flipFor($allowList);
        $unresolvable = self::unresolvableFor($allowList);
        for ($i = 0; $i < $count; ++$i) {
            $name = $m[2][$i];
            // A declared length beyond 18 digits cannot name a real
            // class; comparing digit-string length before the int cast
            // keeps oversized digits from wrapping and coincidentally
            // matching a short name.
            if (strlen($m[1][$i]) > 18 || (int) $m[1][$i] !== strlen($name)) {
                return $name;
            }
            if (!isset($flip[$name]) || isset($unresolvable[$name])) {
                return $name;
            }
        }
        return null;
    }

    /**
     * The names on $allowList the autoloader cannot resolve (usually
     * none — the list is built from a live class map and this package's
     * own classes). Memoized against list identity next to
     * {@see self::flipFor()}; consulting it is what lets the gate
     * promise NO incomplete rather than merely no disallowed name.
     *
     * @param list<class-string> $allowList
     * @return array<string,true>
     */
    private static function unresolvableFor(array $allowList): array
    {
        if ($allowList === self::$unresolvableSource && self::$unresolvableNames !== null) {
            return self::$unresolvableNames;
        }
        $names = [];
        foreach ($allowList as $class) {
            if (!class_exists($class)) {
                $names[$class] = true;
            }
        }
        self::$unresolvableSource = $allowList;
        self::$unresolvableNames  = $names;
        return $names;
    }

    /**
     * The ONE shared graph screen — the shell-S19 bounded, cycle-safe
     * walk of a decoded cache/wire graph, moved here by shell-S11 to the
     * canonical home the plan names ({@see IndexStore::findIncompleteClass()}
     * remains as the delegating compatibility entry so both framed
     * persistence classes and their pins keep working).
     *
     * Looks for the one shape an honest payload cannot contain: a
     * `__PHP_Incomplete_Class` smuggled in at ANY depth by a class
     * outside the allow-list (finding shell-031 repro B hid a stdClass
     * inside a Cluster's `holes` slot — deeper than the flat pre-S19
     * screens could see).
     *
     * A reference-decoded graph is a DAG at best (serialized ASTs
     * share subtrees) and cyclic at worst (a poison can weave `R:`
     * self-references), so the walk expands each object at most once
     * (identity set) and charges every graph edge against $edgeBudget.
     * Out-budget graphs are never declared clean — they come back
     * {@see self::SCAN_UNRESOLVED} and the caller falls back to its
     * posture (stores: a plain miss; the wire guard: fail loud).
     *
     * get_object_vars() from outside a declaring class fires no magic
     * accessor, and every allow-listed class is plain public data, so
     * the walk is side-effect free. It touches only what the load path
     * already decoded — no new memory is manufactured. Its scope is the
     * PUBLIC slots: Node PROTECTED $attributes are not traversed — on
     * the wire that blind spot is closed by the paired bytes screen
     * ({@see self::findBlockedClassInPayload()}), which names every
     * object the payload spells, attribute slots included.
     *
     * @param int $edgeBudget traversal cap; the public signature lets
     *                        the shape tests inject a small budget and
     *                        exercise the unresolved arm in
     *                        milliseconds. Production readers always
     *                        use the default.
     * @return null|string  null when the whole graph walked clean; the
     *                      smuggled class name on the first poison hit;
     *                      the SCAN_UNRESOLVED sentinel on budget-out.
     */
    public static function findIncompleteClass(mixed $root, int $edgeBudget = self::SHAPE_SCAN_EDGES): ?string
    {
        /** @var list<mixed> $pending */
        $pending = [$root];
        /** @var array<int,true> $expanded */
        $expanded = [];
        $edges = 0;

        while ($pending !== []) {
            $node = array_pop($pending);

            if (is_array($node)) {
                foreach ($node as $child) {
                    if (++$edges > $edgeBudget) {
                        return self::SCAN_UNRESOLVED;
                    }
                    if (is_array($child) || is_object($child)) {
                        $pending[] = $child;
                    }
                }
                continue;
            }

            if (!is_object($node)) {
                continue; // scalars cannot hide anything
            }

            $objectId = spl_object_id($node);
            if (isset($expanded[$objectId])) {
                continue; // shared subtree: walked once
            }
            $expanded[$objectId] = true;

            if ($node instanceof \__PHP_Incomplete_Class) {
                $vars = get_object_vars($node);
                $name = $vars['__PHP_Incomplete_Class_Name'] ?? null;
                return is_string($name) && $name !== '' ? $name : '<unknown>';
            }

            foreach (get_object_vars($node) as $child) {
                if (++$edges > $edgeBudget) {
                    return self::SCAN_UNRESOLVED;
                }
                if (is_array($child) || is_object($child)) {
                    $pending[] = $child;
                }
            }
        }

        return null;
    }

    /**
     * Flip the allow-list for O(1) name checks, memoized against the
     * last list identity (see {@see self::$flipSource}).
     *
     * @param list<class-string> $allowList
     * @return array<string,true>
     */
    private static function flipFor(array $allowList): array
    {
        // === on arrays compares element-wise; two references into the
        // same array internals short-circuit, which is exactly the hot
        // case: WorkerPool re-passes the memoized blockCacheClasses().
        if (self::$flip !== null && $allowList === self::$flipSource) {
            return self::$flip;
        }
        $flip = [];
        foreach ($allowList as $class) {
            $flip[$class] = true;
        }
        self::$flip = $flip;
        self::$flipSource = $allowList;
        return $flip;
    }

    /**
     * Linear framing pass over a serialized payload: every container is
     * tracked as a slot budget on an explicit stack (iterative — a
     * hostile payload must not be able to blow the stack of the guard
     * that is supposed to survive it), every string body is skipped by
     * its length prefix, every `O:`/`C:`/`E:` name is checked against
     * the flipped allow-list the moment the grammar reads it.
     *
     * Tag coverage mirrors what serialize() emits for legitimate wire
     * and cache payloads: N; b: i: d: r: R: s: a: O: E: (C: parsed like
     * O:). Anything else — `S:`, `u:`, a truncated header — ends the
     * walk undecided; the caller's graph fallback then owns the verdict.
     *
     * @param array<string,true> $flip
     * @return string|null|self::SCAN_UNDECIDABLE
     */
    private static function walkPayload(string $payload, array $flip): ?string
    {
        $n = strlen($payload);
        $i = 0;
        /** @var list<int> $owed remaining (key,value) slots per open level; last = current */
        $owed = [1]; // the root value

        while ($owed !== []) {
            $top = count($owed) - 1;
            if ($owed[$top] === 0) {
                array_pop($owed);
                if ($owed !== []) {
                    if (($payload[$i] ?? '') !== '}') {
                        return self::SCAN_UNDECIDABLE;
                    }
                    $i++;
                }
                continue;
            }
            $owed[$top]--;
            if ($i >= $n) {
                return self::SCAN_UNDECIDABLE;
            }
            $tag = $payload[$i];

            if ($tag === 'N') {
                if (($payload[$i + 1] ?? '') !== ';') {
                    return self::SCAN_UNDECIDABLE;
                }
                $i += 2;
                continue;
            }

            if (($payload[$i + 1] ?? '') !== ':') {
                return self::SCAN_UNDECIDABLE;
            }

            if ($tag === 'b' || $tag === 'i' || $tag === 'd' || $tag === 'r' || $tag === 'R') {
                // Flat scalar / reference slot: consume up to the ';' terminator.
                $end = $i + 2 + strcspn($payload, ';', $i + 2);
                if (($payload[$end] ?? '') !== ';') {
                    return self::SCAN_UNDECIDABLE;
                }
                $i = $end + 1;
                continue;
            }

            if ($tag === 's') {
                $seg = self::readLengthSegment($payload, $i + 2, $n);
                if ($seg === null) {
                    return self::SCAN_UNDECIDABLE;
                }
                [$len, $j] = $seg;
                if (($payload[$j] ?? '') !== '"'
                    || $n < $j + 1 + $len + 2
                    || substr($payload, $j + 1 + $len, 2) !== '";'
                ) {
                    return self::SCAN_UNDECIDABLE;
                }
                $i = $j + 1 + $len + 2; // body skipped by length — never visited
                continue;
            }

            if ($tag === 'a') {
                $seg = self::readLengthSegment($payload, $i + 2, $n);
                if ($seg === null) {
                    return self::SCAN_UNDECIDABLE;
                }
                [$count, $j] = $seg;
                if (($payload[$j] ?? '') !== '{') {
                    return self::SCAN_UNDECIDABLE;
                }
                $i = $j + 1;
                $owed[] = 2 * $count; // each entry is a key slot + a value slot
                continue;
            }

            if ($tag === 'O' || $tag === 'C') {
                $seg = self::readLengthSegment($payload, $i + 2, $n);
                if ($seg === null) {
                    return self::SCAN_UNDECIDABLE;
                }
                [$nameLen, $j] = $seg;
                if (($payload[$j] ?? '') !== '"' || $n < $j + 1 + $nameLen + 1) {
                    return self::SCAN_UNDECIDABLE;
                }
                if ($payload[$j + 1 + $nameLen] !== '"') {
                    return self::SCAN_UNDECIDABLE;
                }
                $name = substr($payload, $j + 1, $nameLen);
                if (!isset($flip[$name])) {
                    return $name;
                }
                // $j: opening quote, $j+1+$nameLen: closing quote,
                // $j+2+$nameLen: the ':' between them and the count
                // digits — digits start at $j+3+$nameLen.
                $seg = self::readLengthSegment($payload, $j + 3 + $nameLen, $n);
                if ($seg === null) {
                    return self::SCAN_UNDECIDABLE;
                }
                [$count, $k] = $seg;
                if (($payload[$k] ?? '') !== '{') {
                    return self::SCAN_UNDECIDABLE;
                }
                $i = $k + 1;
                $owed[] = 2 * $count;
                continue;
            }

            if ($tag === 'E') {
                // enum leaf: E:<len>:"<Class>:<case>"  ; — PHP >= 8.1
                // consults allowed_classes NOT AT ALL for enums: an
                // existing enum restores its real (stateless,
                // registry-held, hook-free) case whatever the list
                // says, and a missing enum class makes the WHOLE
                // payload fail to decode — WorkerPool's corrupt-frame
                // guard owns that verdict, exactly as before shell-S11.
                // Either way the graph screen never met an incomplete
                // through an E:, so the byte arm refuses none: the
                // token is parsed for framing and skipped. Skipping on
                // refusal instead would be weaker than the graph
                // walk it replaces — enum traffic on a legitimate
                // frame stays allowed, as PHP itself allows it.
                $seg = self::readLengthSegment($payload, $i + 2, $n);
                if ($seg === null) {
                    return self::SCAN_UNDECIDABLE;
                }
                [$tokenLen, $j] = $seg;
                if (($payload[$j] ?? '') !== '"' || $n < $j + 1 + $tokenLen + 2
                    || substr($payload, $j + 1 + $tokenLen, 2) !== '";'
                ) {
                    return self::SCAN_UNDECIDABLE;
                }
                $i = $j + 1 + $tokenLen + 2;
                continue;
            }

            return self::SCAN_UNDECIDABLE; // unknown tag — graph fallback owns it
        }

        return null; // root framed clean; every name admitted
    }

    /**
     * Read `<digits>:` starting at $pos and return [value, offset after
     * the ':']; null when the segment is not a decimal length/count
     * header (bad digits, missing colon, out of buffer).
     *
     * @return array{0:int,1:int}|null
     */
    private static function readLengthSegment(string $payload, int $pos, int $n): ?array
    {
        $digits = strcspn($payload, ':', $pos);
        if ($digits === 0 || $pos + $digits >= $n || ($payload[$pos + $digits] ?? '') !== ':') {
            return null;
        }
        $raw = substr($payload, $pos, $digits);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $value = (int)$raw;
        if ($value < 0 || $value > $n) {
            return null; // a length beyond the buffer cannot be honest
        }
        return [$value, $pos + $digits + 1];
    }

    /**
     * Every class, interface or trait name composer's autoloader knows
     * under the `PhpParser\` prefix — candidate names only. Nothing is
     * admitted on this list; {@see self::parserClasses()} decides
     * membership.
     *
     * @return list<string>
     */
    private static function discoverPhpParserClassNames(): array
    {
        $out = [];
        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (!is_array($autoloader) || count($autoloader) !== 2) {
                continue;
            }
            $loader = $autoloader[0];
            if (!is_object($loader) || !method_exists($loader, 'getClassMap')) {
                continue;
            }
            /** @var array<string,string> $classMap */
            $classMap = $loader->getClassMap();
            foreach (array_keys($classMap) as $fqn) {
                if (is_string($fqn) && str_starts_with($fqn, 'PhpParser\\')) {
                    $out[] = $fqn;
                }
            }
        }
        // If the project was installed without composer's classmap
        // optimizer (`composer install` without `-o`) the map will be
        // empty for these namespaces. Fall back to walking the package
        // directory directly.
        if ($out === []) {
            $out = self::scanPackageDir(__DIR__ . '/../../vendor/nikic/php-parser/lib/PhpParser', 'PhpParser');
        }
        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private static function scanPackageDir(string $dir, string $namespace): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr((string)$file->getPathname(), strlen($dir));
            $rel = ltrim($rel, '/\\');
            $rel = (string)preg_replace('/\.php$/', '', $rel);
            $rel = str_replace(['/', '\\'], '\\', $rel);
            $fqn = rtrim($namespace, '\\') . '\\' . $rel;
            if (class_exists($fqn) || interface_exists($fqn) || trait_exists($fqn)) {
                $out[] = $fqn;
            }
        }
        return $out;
    }
}
