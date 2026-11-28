<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpdup\Parsing\CacheBlob;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\LineRange;
use SplObjectStorage;

/**
 * Walks a file's AST and produces Block instances for the comparable
 * subtrees — the kinds of {@see self::ALL_KINDS} (there is no separate
 * ARCHITECTURE.md; this list is the contract, mirrored in AGENTS.md
 * and docs/CLI.md):
 *
 *   functions, methods, closures, arrow functions, if/elseif chains,
 *   for/foreach/while/do, try/catch, switch, match.
 *
 * Two filters are applied:
 *
 *   - minSize: nodes smaller than this are dropped (kills boilerplate).
 *   - maxSize: nodes larger than this no longer vanish silently (items
 *     0.6 + 1.2). With window_oversized on (the default) their statement
 *     bodies are cut into SCAN WINDOWS — contiguous runs of statements
 *     wrapped in a synthetic Stmt\Block, each sized ≤ maxSize and ≥
 *     minSize — so an oversized function stays fingerprintable and the
 *     n-gram/containment tiers can still pair it. A window is SYNTHETIC
 *     like the 1.3 'script' fold: it gets the dedicated 'window' kind
 *     (never in ALL_KINDS, never --kinds-selectable), keeps its AST for
 *     the whole run, and every AST-addressing surface (apply, patch,
 *     loader relocation) refuses it — see WindowApplyRefusal.
 *     Unwindowable oversized nodes (arrow/switch/match: no plain
 *     statement list) and everything dropped while windowing is off are
 *     counted by oversizedDrops() at parent granularity (a fully dropped
 *     parent counts once); from a SALVAGED parent, the statements the
 *     window packing silently gave up on (invalid geometry, sub-floor
 *     remainders) count individually — the disclosed lone-giant residual
 *     aside. Either way oversizedDrops() feeds the
 *     "N blocks/statements exceeded the max block size and were dropped"
 *     line and summary.blocks_oversized.
 *
 * Declaration-shape kinds — function, method, closure and arrow — always
 * pass the minSize filter: those are top-level abstractions worth
 * comparing even if small (engine-057: 'arrow' was missing from the
 * exemption, so tiny `fn` declarations were silently dropped against
 * this very promise even though ALL_KINDS mints them).
 *
 * Item 1.3 adds a thirteenth kind, 'script': a folded run of top-level
 * statements (requires/includes/auth-guards/bootstrap sequences — the
 * copy-paste shape that lives OUTSIDE any function in classic procedural
 * PHP). 'script' is OPT-IN ONLY: it never joins the implicit
 * accept-all list (empty allowedKinds), because top-level fold blocks
 * must be a deliberate choice — they change what "a block" means for a
 * file, and the apply/extraction machinery cannot relocate them yet.
 *
 * Item 2.1 adds a fourteenth kind, 'fragment': a contiguous run of
 * statements (k = 3..40, the doc's fragment_k and cap) mined from EVERY
 * executable statement list of a file — function/method/closure bodies,
 * loop and branch bodies, and the same top-level buckets 'script' folds.
 * Fragments are how class-B findings ("these 9 consecutive statements
 * appear in 114 functions") become visible: they address runs ACROSS
 * declaration boundaries, where whole-unit kinds see only the enclosing
 * declaration or the inner branches in isolation. Like 'script' the kind
 * is OPT-IN ONLY (never implicit), mints into synthetic Stmt\Block
 * wrappers, is refused by every AST-addressing surface
 * ({@see \Phpdup\Refactor\FragmentApplyRefusal}) and keeps its AST.
 * Identity is two-layered: the regular per-block fold (structuralHash,
 * ngramBag) comes from normalizing the window alone — so content-equal
 * runs cluster exactly like any other species — while list-relative
 * metadata ({@see Block::$fragmentKey} $fragmentPrev/$fragmentNext
 * $fragmentPos $fragmentSize $fragmentWhole) is computed by
 * PreprocessWorker over ONE whole-list normalization, giving the
 * maximality-suppression dictionary its corpus-consistent keys. The
 * whole run of a list whose complete content is already minted (a
 * declaration body whose function/method/closure block survived, a
 * top-level bucket the script fold minted) is flagged $fragmentWhole and
 * suppressed: it equals a whole block that already exists.
 *
 * The §5c shared-miner contract (improve_logic_info.md): ONE walker
 * (BlockVisitor's list collection, gated on demand), ONE generic scalar
 * row stream ('mined' rows from PreprocessWorker, flavor-keyed payloads —
 * v1 flavor stmtseq), ONE index (SequenceIndex). Items 3.1/3.2/3.5 add
 * flavors and consumers WITHOUT a second traversal.
 *
 * Item 2.3 adds a fifteenth kind, 'class': a NAMED class/interface/trait/
 * enum declaration block whose whole-class identity is its SHAPE — the
 * member-token skeleton minted by {@see \Phpdup\Normalization\ShapeFingerprint}
 * (member names with arity and visibility, constant names, implemented
 * interfaces, backing enums — over the name-blanked canonical tree).
 * Declaration twins (same shape, different name: a pair of VOs or
 * interfaces) hash byte-identically; skeleton families ride the regular
 * ngram/TED tiers over the same skeleton. OPT-IN ONLY like the two rows
 * above, and enforced at the MINT (classifyKind never returns 'class'
 * unless the roster names it — the accept-all stance would otherwise
 * move every golden floor). Unlike script/window/fragment the kind IS
 * relocatable by the loader (a real parse-tree declaration); what it
 * refuses is apply/patch — a whole-class extraction is out of v1 scope
 * ({@see \Phpdup\Refactor\ClassKindApplyRefusal}).
 *
 * Item 7.2b adds a sixteenth kind, 'html_script': the CONTENT of inline
 * <script> windows of a template. Before 7.2b an inline-HTML run was only
 * ever seen OUTSIDE the &lt;?php bubbles — groupTopLevel() drops
 * Stmt\InlineHTML as "structure, not logic" — so two .phtml files sharing
 * a byte-identical JS block behind different HTML chrome were invisible to
 * every species. The 7.2b leg scans the RAW-TEXT surface with the same
 * one-walker discipline: every statement list the §5c miner collects (plus
 * fragment-style top-level buckets that KEEP the InlineHTML runs as
 * position-marked sentinels where the script fold drops them — see
 * {@see self::groupTopLevelFragments}) is streamed through a window
 * scanner ({@see self::scanScriptWindows}): an opener &lt;script ...&gt;
 * without a src= attribute opens a window; the body up to the matching
 * &lt;/script — possibly ACROSS ?&gt;/&lt;?php escapes, i.e. across
 * several InlineHTML runs with real PHP statements in between — is the
 * fold. The fold's wrapper carries the FULL fragment sequence: raw-text
 * runs as markup/slice sentinel entries (slices capped at
 * {@see self::SCRIPT_TEXT_WINDOW_BYTES} bytes, position-marked so line
 * identity survives chunking), real statements in between as real Nodes,
 * and the Block records the per-member lineage ({@see
 * Block::$fragmentLineage}: 'markup' | 'slice' | 'stmt') so the loader can
 * re-derive the wrapper positionally from a fresh parse
 * ({@see \Phpdup\Extraction\BlockAstLoader}'s html_script arm) — raw text
 * hashes AS raw text through the shared AstSerializer/SubtreeFold formula
 * (identity across runs; the escaped form &amp;#104;&amp;#101;... never
 * equals &lt;h1&gt; because the entity bytes ride the token stream
 * verbatim). language='text/babel' (and type= variants) are ordinary
 * windows: v1 folds the raw text it holds, no JS grammar. OPT-IN ONLY like
 * the three rows above (never implicit — it adds blocks to every
 * template-bearing corpus), oversized windows pack with WindowPacker like
 * the script fold (the pieces carry kind 'window', existing refusal law),
 * and mixed-language APPLY/patch is refused in v1
 * ({@see \Phpdup\Refactor\HtmlFragmentApplyRefusal}) — extraction,
 * clustering, signatures and reporting all work.
 */
final class BlockExtractor
{
    public const ALL_KINDS = [
        'function', 'method', 'closure', 'arrow',
        'if', 'for', 'foreach', 'while', 'do',
        'try', 'switch', 'match',
        // Item 1.3: opt-in top-level script blocks — NOT part of the
        // implicit accept-all set (see class docblock and extract()).
        'script',
        // Item 2.1: opt-in statement-run fragments from the shared miner —
        // likewise never implicit (they change what clustering sees);
        // named explicitly like 'script'.
        'fragment',
        // Item 2.3: opt-in class-declaration blocks fingerprinted by
        // their SHAPE — never implicit for the same reason as the two
        // rows above (they move the clustering floor of every existing
        // species); the never-implicit law is enforced at the MINT
        // (classifyKind), see CLASS_KIND.
        'class',
        // Item 7.2b: opt-in inline-<script> content windows of templates —
        // never implicit like the three rows above; classifyKind never
        // mints it, only extract()'s 7.2b leg does (see HTML_SCRIPT_KIND).
        'html_script',
        // Item 7.1: opt-in lexical token runs of NON-PHP files (the native
        // token tier — js/ts/json/yaml/sh/css/tpl/twig/html windows). Never
        // implicit like the four rows above, and minted by NEITHER
        // classifyKind NOR extract(): this kind exists only through the
        // demand-gated harvester in PreprocessStage (see TEXT_KIND),
        // because its files are not PHP and never reach this extractor.
        'text',
    ];

    /**
     * Item 2.3: the class-declaration kind (named class/interface/trait/
     * enum). It IS in ALL_KINDS (a user selects it with --kinds) and
     * classifyKind DOES mint it — but ONLY when the roster explicitly
     * names it: the visitor's accept-all stance (empty roster = every
     * classified kind passes) must not silently start minting class
     * blocks, or default runs would move the golden floor. Anonymous
     * classes are never a declaration twin and stay out (no name).
     * The kind's whole-class identity is the name-blanked SKELETON
     * ({@see \Phpdup\Normalization\ShapeFingerprint::skeleton()});
     * apply/patch refuse it in v1 ({@see \Phpdup\Refactor\ClassKindApplyRefusal}),
     * the loader relocates it (it is a real parse-tree declaration).
     */
    public const CLASS_KIND = 'class';

    /**
     * Item 2.1: the synthetic kind of a mined statement-run fragment. It IS
     * in ALL_KINDS (a user selects it with --kinds, like 'script') but
     * classifyKind never mints it — only the miner leg of extract() does
     * (drift-pinned by BlockExtractorTest and BlockExtractorFragmentTest).
     */
    public const FRAGMENT_KIND = 'fragment';

    /** Item 2.1 doc parameter: fragment_k — smallest mined run. */
    public const FRAGMENT_MIN_STATEMENTS = 3;

    /** Item 2.1 doc parameter: cap 40 statements per mined run. */
    public const FRAGMENT_MAX_STATEMENTS = 40;

    /**
     * Item 7.2b: the synthetic kind of an inline-&lt;script&gt; content
     * window of a template. It IS in ALL_KINDS (a user selects it with
     * --kinds, like 'script' and 'fragment') but classifyKind never mints
     * it — only the 7.2b leg of extract() does. The mint is a FULL
     * fragment fold: raw-HTML runs ride as position-marked sentinel
     * entries, real statements in between ride as real Nodes, and
     * {@see Block::$fragmentLineage} records which member is which so the
     * loader can re-derive the wrapper from a fresh parse. apply/patch
     * refuse it in v1 ({@see \Phpdup\Refactor\HtmlFragmentApplyRefusal}).
     */
    public const HTML_SCRIPT_KIND = 'html_script';

    /**
     * Item 7.1: the lexical kind of the native token tier. It IS in
     * ALL_KINDS (a user selects it with --kinds, like the opt-in rows
     * above) but classifyKind and extract() never mint it: the tier's
     * files are not PHP, so its blocks are minted ONLY by the demand-gated
     * cross-file harvester in {@see \Phpdup\Text\TextRunHarvester} (fed by
     * {@see \Phpdup\Text\TextBlockFactory}). The fold carrier is a
     * synthetic InlineHTML over the blanked token stream (T1a's raw-text
     * identity channel); apply/patch refuse the kind in v1
     * ({@see \Phpdup\Refactor\TextKindApplyRefusal}).
     */
    public const TEXT_KIND = 'text';

    /**
     * Item 7.2b: largest raw-text sentinel slice the window scanner cuts
     * out of an InlineHTML run when a window begins or ends mid-run (whole
     * untouched interior runs are kept as the original node). The cap
     * bounds what ONE member can contribute to a fold's node weight; it is
     * a v1 constant, not a knob (the mission's zero-new-knobs ruling).
     */
    public const SCRIPT_TEXT_WINDOW_BYTES = 4096;

    /**
     * Item 7.2b: bytes of raw markup counted as ONE pseudo-node when
     * weighting a fold (mirrors the ~avg AST node size so HTML runs and
     * statements share one weight currency; the same formula feeds the
     * minSize/maxSize gates and WindowPacker for this species).
     */
    public const SCRIPT_PSEUDO_NODE_BYTES = 32;

    /**
     * Item 1.2: the synthetic kind of a scan window cut out of an
     * oversized block. Deliberately NOT in ALL_KINDS: a window is a
     * byproduct of the size ceiling, never something the user selects
     * with --kinds, and classifyKind never mints it (drift-pinned by
     * BlockExtractorWindowTest and BlockExtractorTest's ALL_KINDS pin).
     */
    public const WINDOW_KIND = 'window';

    /** Drop count of the most recent extract() call (item 0.6). */
    private int $lastOversizedDrops = 0;

    /**
     * Statement lists the most recent extract() collected for the §5c
     * shared miner (empty unless 'fragment' ∈ allowedKinds): per list the
     * live statement nodes, the owner-kind label, the namespace/class
     * context, and the fragment Blocks minted from it (see extract()'s
     * fragment leg and PreprocessWorker's mining leg for the consumer).
     *
     * @var list<array{stmts: list<Node\Stmt>, fragments: list<Block>, ownerKind: string, namespace: ?string, class: ?string}>
     */
    private array $lastStatementLists = [];

    /**
     * @param list<string> $allowedKinds Empty list = accept all twelve
     *        traversable kinds — the 1.3 'script', 2.1 'fragment', 2.3
     *        'class' and 7.2b 'html_script' fold kinds are never implicit,
     *        they must be named (drift-pinned by BlockExtractorTest and
     *        ScriptKindTest).
     */
    public function __construct(
        private readonly int $minSize = 8,
        private readonly int $maxSize = 800,
        private readonly array $allowedKinds = [],
        // Item 1.2: window oversized blocks instead of dropping them.
        // Root config-only knob `window_oversized`; the per-file cache
        // key binds it in every store (three-key law), so flipping the
        // posture invalidates warm snapshots instead of mixing species.
        private readonly bool $windowOversized = true,
    ) {
    }

    /**
     * Oversized mass the MOST RECENT extract() gave up on (item 0.6), at
     * the granularity that escaped: nodes above maxSize from which no
     * window survived the minSize floor count ONCE each (with windowing
     * off, every oversized node); a SALVAGED parent is itself not counted,
     * but statements its window packing silently gave up on (invalid
     * line-geometry, sub-floor remainders — see WindowPacker) each add one
     * to the same ledger. Fresh-extraction
     * semantics only: cache-reused files never re-extract, so their
     * historical drops are not recounted (same run-scope honesty as the
     * skipped-files counter).
     */
    public function oversizedDrops(): int
    {
        return $this->lastOversizedDrops;
    }

    /**
     * The §5c miner's per-file product for the MOST RECENT extract():
     * every executable statement list the walker collected (declaration,
     * branch and top-level-bucket lists — class-like bodies are NOT
     * lists: their members are declarations, already units) together with
     * the fragment Blocks minted from it. PreprocessWorker consumes this
     * in-process (same fork) to compute the whole-list statement-hash
     * sequences and decorate the fragments' list-relative keys; the
     * sequences themselves leave the process ONLY as scalar 'mined' row
     * payloads (SOCKETPAIR law). Live AST nodes — never serialize.
     *
     * @return list<array{stmts: list<Node\Stmt>, fragments: list<Block>, ownerKind: string, namespace: ?string, class: ?string}>
     */
    public function lastStatementLists(): array
    {
        return $this->lastStatementLists;
    }

    /**
     * @param list<Node\Stmt> $stmts
     * @return list<Block>
     */
    public function extract(string $file, array $stmts): array
    {
        // engine-004 (deep-AST family): php-parser's NodeTraverser recurses
        // one frame per AST level, so a nesting depth the (iterative) parser
        // itself survives — e.g. a 10k-deep array literal — blows the C
        // stack here with a segfault, not a catchable Error: pre-fix, an
        // over-deep statement killed the ENTIRE run and yielded zero
        // blocks for the whole file. Whole top-level statements past the
        // shared CacheBlob depth budget are pruned before traversal. The
        // measured cost is confined to pathological input: a tiny kind
        // node buried at thousands of levels of nesting (e.g. the
        // innermost arrow in a 10k-deep fn-chain) stays invisible, while
        // every other statement of the file keeps full extraction
        // semantics — strictly more detection than the segfault baseline,
        // and the pipeline now survives to analyse the rest of the corpus.
        $traversable = [];
        foreach ($stmts as $stmt) {
            if (CacheBlob::exceedsDepth([$stmt])) {
                continue;
            }
            $traversable[] = $stmt;
        }

        $blocks = [];
        $oversized = 0;
        $htmlScript = in_array(self::HTML_SCRIPT_KIND, $this->allowedKinds, true);
        $traverser = new NodeTraverser();
        $visitor = new BlockVisitor(
            file: $file,
            minSize: $this->minSize,
            maxSize: $this->maxSize,
            allowedKinds: $this->allowedKinds,
            sink: function (Block $b) use (&$blocks): void { $blocks[] = $b; },
            windowOversized: $this->windowOversized,
            // §5c one-walker law: the 7.2b leg REUSES the lists the
            // fragment miner collects — the gate widens to "either species
            // named", collection stays demand-gated, and the wire contract
            // (lastStatementLists() = fragment product only, filled ONLY
            // under 'fragment') is untouched for PreprocessWorker.
            mineLists: in_array(self::FRAGMENT_KIND, $this->allowedKinds, true) || $htmlScript,
        );
        $traverser->addVisitor($visitor);
        $traverser->traverse($traversable);
        $oversized += $visitor->oversizedDrops;
        // Item 1.3: 'script' is opt-IN ONLY — the empty allow-list means
        // "all traversable kinds", never "also fold top-level statements".
        // Folded blocks therefore append after the visitor's blocks in a
        // fixed, deterministic order (per-scope, first-line ordered).
        if (in_array('script', $this->allowedKinds, true)) {
            foreach (self::groupTopLevel($stmts) as $group) {
                if ($group['stmts'] === []) {
                    continue; // groupTopLevel never emits empty buckets; belt for the offset below
                }
                $first = $group['stmts'][0];
                $last  = $group['stmts'][\count($group['stmts']) - 1];
                $start = $first->getStartLine();
                $end   = $last->getEndLine();
                if ($start <= 0 || $end <= 0) {
                    continue; // same line-guard the visitor applies
                }
                // A synthetic Block node wraps the folded statement run so
                // the block carries a real Node as canonical/ast — the
                // probe fact from the research filing: pipeline and caches
                // accept Stmt\Block wrappers unchanged.
                $wrapper = new Stmt\Block(array_values($group['stmts']), [
                    'startLine' => $start,
                    'endLine'   => $end,
                ]);
                $size = AstSerializer::nodeCount($wrapper);
                // Uniform size posture: scripts are gated by BOTH floors —
                // the minSize filter (they are not declarations) and the
                // maxSize ceiling (a whole-file fold must not smuggle past
                // the cost ceiling the traversable kinds obey). Item 1.2:
                // an oversized fold windows like any statement-list owner
                // (the twin of the visitor's oversized leg); a fold from
                // which nothing is salvaged counts once at the 0.6 counter,
                // a salvaged fold contributes its individually dropped
                // statements (WindowPacker's droppedStatements).
                if ($size < $this->minSize) {
                    continue;
                }
                if ($size > $this->maxSize) {
                    $pack = $this->windowOversized
                        ? WindowPacker::emitWindowRuns(
                            file: $file,
                            namespace: $group['namespace'],
                            class: null,
                            name: null,
                            stmts: $group['stmts'],
                            minSize: $this->minSize,
                            maxSize: $this->maxSize,
                            count: static fn (Node $n): int => AstSerializer::nodeCount($n),
                            sink: function (Block $b) use (&$blocks): void { $blocks[] = $b; },
                        )
                        : ['emitted' => 0, 'droppedStatements' => 0];
                    if ($pack['emitted'] > 0) {
                        // E2 r1 MINOR-1: a salvaged fold is not itself a
                        // drop, but statements its packing gave up on
                        // count individually — nothing invisible escapes.
                        $oversized += $pack['droppedStatements'];
                    } else {
                        $oversized++;
                    }
                    continue;
                }
                $block = new Block(
                    file: $file,
                    range: new LineRange($start, $end),
                    kind: 'script',
                    namespace: $group['namespace'],
                    class: null,
                    name: null,
                    ast: $wrapper,
                );
                $block->size = $size;
                $blocks[] = $block;
            }
        }

        // Item 2.1: the shared-miner leg (§5c). Demand-gated — with
        // 'fragment' unnamed, NO list is walked and NO window minted, so
        // default runs stay byte-identical to the pre-2.1 pipeline by
        // construction. The visitor collected the executable statement
        // lists during its (already-running) traversal — one walker, the
        // 5c law — and top-level buckets reuse the exact groupTopLevel()
        // map the script kind folds. Every contiguous run of
        // FRAGMENT_MIN..MAX statements becomes a synthetic Stmt\Block
        // wrapper Block of kind 'fragment', gated by the same minSize/
        // maxSize floors as the script fold (a run above the cost ceiling
        // dies; these ARE the cuts — nothing re-windows them). Maximality
        // is a CORPUS fact and is NOT decided here: PreprocessStage's
        // suppression pass drops runs whose equal-or-greater-support
        // witnesses exist, from the list-relative fields the worker
        // decorates — see that stage. A whole-list run whose complete
        // content is already a minted block (the declaration's own body,
        // or a top-level bucket the script fold minted) is flagged
        // $fragmentWhole: the doc's "equals a whole block already
        // clustered" suppression, expressed pre-cluster on extraction
        // evidence.
        $this->lastStatementLists = [];
        if (in_array(self::FRAGMENT_KIND, $this->allowedKinds, true)) {
            $listMeta = $visitor->stmtLists;
            foreach (self::groupTopLevel($stmts) as $group) {
                if ($group['stmts'] === []) {
                    continue;
                }
                $listMeta[] = [
                    'stmts' => $group['stmts'],
                    'ownerKind' => '',
                    'namespace' => $group['namespace'],
                    'class' => null,
                ];
            }
            $mintedDecls = [];
            $mintedFolds = [];
            foreach ($blocks as $b) {
                if ($b->kind === 'script') {
                    $mintedFolds[$b->range->start . ':' . $b->range->end] = true;
                } elseif ($b->kind === 'function' || $b->kind === 'method' || $b->kind === 'closure') {
                    $mintedDecls[] = [$b->range->start, $b->range->end];
                }
            }
            foreach ($listMeta as $entry) {
                $frags = $this->mintFragmentRuns(
                    $file,
                    $entry['stmts'],
                    $entry['ownerKind'],
                    $entry['namespace'],
                    $entry['class'],
                    $mintedDecls,
                    $mintedFolds,
                    $visitor,
                );
                $this->lastStatementLists[] = [
                    'stmts' => $entry['stmts'],
                    'fragments' => $frags,
                    'ownerKind' => $entry['ownerKind'],
                    'namespace' => $entry['namespace'],
                    'class' => $entry['class'],
                ];
                foreach ($frags as $f) {
                    $blocks[] = $f;
                }
            }
        }

        // Item 7.2b: the inline-<script> window leg. With the kind unnamed
        // it never runs; named, the sequences are the §5c lists the SAME
        // visitor already collected (collection is gate-independent — one
        // walker, the 5c law) plus the fragment-style top-level buckets
        // that KEEP the InlineHTML sentinels the script fold drops. Each
        // window mints as a FULL fragment fold: raw-text runs ride as
        // 'markup'/'slice' sentinel members, real statements in between as
        // real Nodes, Block::$fragmentLineage records the per-member
        // classes so BlockAstLoader re-derives the wrapper positionally
        // from a fresh parse. Weight currency: one pseudo-node per
        // SCRIPT_PSEUDO_NODE_BYTES bytes of raw text (a class constant,
        // not a knob) — the SAME gates as every other species decide
        // emission; an oversized window packs via WindowPacker (pieces
        // carry kind 'window', existing refusal law).
        if ($htmlScript) {
            $sequences = [];
            foreach ($visitor->stmtLists as $list) {
                $sequences[] = [
                    'stmts' => $list['stmts'],
                    'namespace' => $list['namespace'],
                    'class' => $list['class'],
                ];
            }
            foreach (self::groupTopLevelFragments($stmts) as $group) {
                $sequences[] = [
                    'stmts' => $group['stmts'],
                    'namespace' => $group['namespace'],
                    'class' => null,
                ];
            }
            // Weight closure for this leg: markup runs in bytes, synthetic
            // wrappers summed over their members (WindowPacker's count
            // seam re-enters here for its wrappers), real statements via
            // the visitor's memoized node seam.
            $textAware = static function (Node $n) use ($visitor, &$textAware): int {
                if ($n instanceof Stmt\InlineHTML) {
                    return self::markupWeight($n->value);
                }
                if ($n instanceof Stmt\Block) {
                    $w = 1;
                    foreach ($n->stmts as $member) {
                        $w += $textAware($member);
                    }
                    return $w;
                }
                return $visitor->countNodes($n);
            };
            foreach ($sequences as $seq) {
                foreach (self::scanScriptWindows($seq['stmts']) as $win) {
                    if ($win['startLine'] <= 0 || $win['endLine'] <= 0
                        || $win['endLine'] < $win['startLine']) {
                        continue; // same line-guard the folds apply
                    }
                    $size = self::sequenceWeight($win['members'], $textAware);
                    if ($size < $this->minSize) {
                        continue; // boilerplate posture: tiny windows are noise
                    }
                    if ($size > $this->maxSize) {
                        $pack = $this->windowOversized
                            ? WindowPacker::emitWindowRuns(
                                file: $file,
                                namespace: $seq['namespace'],
                                class: $seq['class'],
                                name: null,
                                stmts: $win['members'],
                                minSize: $this->minSize,
                                maxSize: $this->maxSize,
                                count: $textAware,
                                sink: function (Block $b) use (&$blocks): void {
                                    if ($b->ast instanceof Stmt\Block) {
                                        $b->fragmentLineage = self::lineageClasses($b->ast->stmts);
                                    }
                                    $blocks[] = $b;
                                },
                            )
                            : ['emitted' => 0, 'droppedStatements' => 0];
                        if ($pack['emitted'] > 0) {
                            $oversized += $pack['droppedStatements'];
                        } else {
                            $oversized++;
                        }
                        continue;
                    }
                    $wrapper = new Stmt\Block(array_values($win['members']), [
                        'startLine' => $win['startLine'],
                        'endLine' => $win['endLine'],
                    ]);
                    $block = new Block(
                        file: $file,
                        range: new LineRange($win['startLine'], $win['endLine']),
                        kind: self::HTML_SCRIPT_KIND,
                        namespace: $seq['namespace'],
                        class: $seq['class'],
                        name: null,
                        ast: $wrapper,
                    );
                    $block->size = $size;
                    $block->fragmentLineage = $win['classes'];
                    $blocks[] = $block;
                }
            }
        }

        $this->lastOversizedDrops = $oversized;
        return $blocks;
    }

    /**
     * Every contiguous run of {@see self::FRAGMENT_MIN_STATEMENTS}..
     * {@see self::FRAGMENT_MAX_STATEMENTS} statements of one list, as a
     * minted 'fragment' Block (the whole list is NOT exempt: it is minted
     * and flagged when its content already rides a minted block — the
     * caller-side suppression needs the KEY of every run as its
     * maximality witness, and whole flags travel with real Blocks).
     * Node sizes come from the visitor's memoized per-statement counts
     * via prefix sums: window cost = wrapper node (1) + member sum.
     *
     * @param list<Node\Stmt>                  $stmts
     * @param list<array{0:int, 1:int}>        $mintedDecls  declaration blocks this file minted
     * @param array<string, bool>              $mintedFolds  fold ranges the script leg minted
     * @return list<Block>
     */
    private function mintFragmentRuns(
        string $file,
        array $stmts,
        string $ownerKind,
        ?string $namespace,
        ?string $class,
        array $mintedDecls,
        array $mintedFolds,
        BlockVisitor $visitor,
    ): array {
        $count = count($stmts);
        $maxWindow = min(self::FRAGMENT_MAX_STATEMENTS, $count);
        if ($maxWindow < self::FRAGMENT_MIN_STATEMENTS) {
            return [];
        }

        $prefix = [0];
        $firstLines = [];
        $lastLines = [];
        foreach ($stmts as $i => $s) {
            $prefix[$i + 1] = $prefix[$i] + $visitor->countNodes($s);
            $firstLines[$i] = $s->getStartLine();
            $lastLines[$i] = $s->getEndLine();
        }

        $isDeclaration = in_array($ownerKind, ['function', 'method', 'closure'], true);
        $coversList = false;
        if ($isDeclaration) {
            $listStart = $firstLines[0];
            $listEnd = $lastLines[$count - 1];
            foreach ($mintedDecls as [$s, $e]) {
                if ($s <= $listStart && $e >= $listEnd) {
                    $coversList = true;
                    break;
                }
            }
        }

        $runs = [];
        for ($size = self::FRAGMENT_MIN_STATEMENTS; $size <= $maxWindow; $size++) {
            $wholePossible = $size === $count;
            for ($pos = 0; $pos + $size <= $count; $pos++) {
                $start = $firstLines[$pos];
                $end = $lastLines[$pos + $size - 1];
                if ($start <= 0 || $end <= 0 || $end < $start) {
                    continue; // same line-guard the visitor and fold apply
                }
                $nodeSize = 1 + $prefix[$pos + $size] - $prefix[$pos];
                if ($nodeSize < $this->minSize || $nodeSize > $this->maxSize) {
                    continue;
                }
                $whole = false;
                if ($wholePossible) {
                    if ($isDeclaration && $coversList) {
                        $whole = true;
                    } elseif ($ownerKind === '' && isset($mintedFolds[$start . ':' . $end])) {
                        $whole = true;
                    }
                }
                $wrapper = new Stmt\Block(array_slice($stmts, $pos, $size), [
                    'startLine' => $start,
                    'endLine'   => $end,
                ]);
                $block = new Block(
                    file: $file,
                    range: new LineRange($start, $end),
                    kind: self::FRAGMENT_KIND,
                    namespace: $namespace,
                    class: $class,
                    name: null,
                    ast: $wrapper,
                );
                $block->size = $nodeSize;
                $block->fragmentPos = $pos;
                $block->fragmentSize = $size;
                $block->fragmentWhole = $whole;
                $runs[] = $block;
            }
        }
        return $runs;
    }

    /**
     * The 'script' kind's fold map: bucket the DECLARATIVE-free top-level
     * statements of a file into per-scope runs. Declarations (classes,
     * interfaces, traits, enums, functions), namespace/use/declare
     * bookkeeping, inline HTML and no-ops are NOT folded — they already
     * have a traversable kind or are structure, not logic. A top-level
     * `const` DECLARATION is excluded for the same reason; an
     * Expression-wrapped `require`/`echo`/call is exactly what IS folded.
     *
     * Bracketed namespaces fold in their own scope (two namespaces never
     * mix into one block — mirrors BlockVisitor's namespace tracking);
     * file-root statements fold under namespace=null.
     *
     * @param list<Node\Stmt> $stmts
     * @return list<array{namespace: ?string, stmts: list<Node\Stmt>}>
     */
    public static function groupTopLevel(array $stmts): array
    {
        return self::bucketGroups($stmts, false);
    }

    /**
     * Item 7.2b: the script fold's bucket law WITH the InlineHTML
     * restriction lifted — the html_script sequences need the raw-text
     * surface the fold drops ("structure, not logic" is precisely the
     * logic THIS species folds). Bucketing, bracketed-namespace scoping,
     * root-bucket-first order and empty-drop are IDENTICAL to
     * {@see self::groupTopLevel()}, so the two fold kinds walk the same
     * PHP runs and differ only by the markup sentinels.
     *
     * @param list<Node\Stmt> $stmts
     * @return list<array{namespace: ?string, stmts: list<Node\Stmt>}>
     */
    public static function groupTopLevelFragments(array $stmts): array
    {
        return self::bucketGroups($stmts, true);
    }

    /**
     * Shared bucketing of {@see self::groupTopLevel()} /
     * {@see self::groupTopLevelFragments()}; $keepHtml is the ONLY
     * behavioural difference (the script fold has dropped inline HTML
     * since item 1.3 — zero behavior change through this refactor).
     *
     * @param list<Node\Stmt> $stmts
     * @return list<array{namespace: ?string, stmts: list<Node\Stmt>}>
     */
    private static function bucketGroups(array $stmts, bool $keepHtml): array
    {
        $groups = [];
        $foldable = static function (Node\Stmt $s) use ($keepHtml): bool {
            return !($s instanceof Stmt\ClassLike)      // class/interface/trait/enum
                && !($s instanceof Stmt\Function_)
                && !($s instanceof Stmt\Use_)           // covers GroupUse (extends Use_)
                && !($s instanceof Stmt\Declare_)
                && !($s instanceof Stmt\Nop)
                && !($s instanceof Stmt\Const_)
                && !($s instanceof Stmt\Namespace_)     // folded via its own bucket
                && !($s instanceof Stmt\HaltCompiler)
                && ($keepHtml || !($s instanceof Stmt\InlineHTML));
        };

        $root = [];
        foreach ($stmts as $s) {
            if ($s instanceof Stmt\Namespace_) {
                $inner = [];
                foreach ($s->stmts as $nsStmt) {
                    if ($foldable($nsStmt)) {
                        $inner[] = $nsStmt;
                    }
                }
                $groups[] = ['namespace' => $s->name?->toString(), 'stmts' => $inner];
                continue;
            }
            if ($foldable($s)) {
                $root[] = $s;
            }
        }
        if ($root !== []) {
            array_unshift($groups, ['namespace' => null, 'stmts' => $root]);
        }
        // Empty-scope buckets (bracketed namespace with only declarations)
        // carry nothing to fold — drop them.
        return array_values(array_filter($groups, static fn (array $g): bool => $g['stmts'] !== []));
    }

    /**
     * Item 7.2b: attribute marking a synthetic raw-text SLICE (a piece cut
     * out of an InlineHTML run where a window began or ended). Real parser
     * output never carries it — the lineage classifier reads it back
     * ('slice' vs 'markup') and the IndexStore blob round-trip preserves
     * both the value bytes and the line attributes (scalars only).
     */
    private const HTML_SLICE_ATTR = 'phpdupHtmlSlice';

    /**
     * Item 7.2b: stream a statement sequence through the raw-text surface
     * and return every inline-&lt;script&gt; window it carries. Law (v1,
     * all disclosed postures):
     *
     *  - an opener {@script-tag-regex} with a src= attribute is an
     *    EXTERNAL script: skipped whole (past its closer when one is in
     *    the same text run) — the file's own bytes carry no content to
     *    fold;
     *  - otherwise the window runs from just past the opener to the next
     *    {@script-close-regex} — POSSIBLY ACROSS ?&gt;/&lt;?php escapes,
     *    i.e. across several runs with real statements in between (those
     *    statements ride the fold as 'stmt' members: the mixed-language
     *    crux of the item);
     *  - a window unclosed at the end of the SEQUENCE is DISCARDED — a
     *    template that never closes cannot define a bounded fold;
     *  - a {@script-close-regex} appearing inside PHP string content
     *    (not raw text) is NOT tracked — v1 scans the raw-text surface
     *    only (documented residual; the same posture language parsers
     *    take on unbalanced fences).
     *
     * Interior runs the window only touches at its edges are cut into
     * ≤ SCRIPT_TEXT_WINDOW_BYTES slices; an untouched whole run is kept
     * as the ORIGINAL node (class 'markup'), so no-chrome windows hash
     * identically whether or not slicing occurred elsewhere.
     *
     * @param list<Node\Stmt> $members
     * @return list<array{members: list<Node\Stmt>, classes: list<string>, startLine: int, endLine: int}>
     */
    public static function scanScriptWindows(array $members): array
    {
        $windows = [];
        /** @var null|array{members: list<Node\Stmt>, classes: list<string>, startLine: int, endLine: int} $cur */
        $cur = null;
        foreach ($members as $stmt) {
            if (!$stmt instanceof Stmt\InlineHTML) {
                if ($cur !== null) {
                    $cur['members'][] = $stmt;
                    $cur['classes'][] = 'stmt';
                    $end = $stmt->getEndLine();
                    if ($end > $cur['endLine']) {
                        $cur['endLine'] = $end;
                    }
                }
                continue;
            }
            $text = $stmt->value;
            $len = strlen($text);
            if ($len === 0) {
                continue;
            }
            $base = $stmt->getStartLine();
            $pos = 0;
            while ($pos < $len) {
                if ($cur === null) {
                    if (preg_match('/<script\b[^>]*>/i', $text, $m, PREG_OFFSET_CAPTURE, $pos) !== 1) {
                        break; // no further opener in this run
                    }
                    $tag = $m[0][0];
                    $bodyStart = $m[0][1] + strlen($tag);
                    if (preg_match('/\bsrc\s*=/i', $tag) === 1) {
                        // External script: no inline content — skip past
                        // its closer (or the run end; the closer of a
                        // src-bearing tag is conventionally adjacent).
                        $pos = $bodyStart;
                        if (preg_match('/<\/script\b[^>]*>/i', $text, $cm, PREG_OFFSET_CAPTURE, $pos) === 1) {
                            $pos = $cm[0][1] + strlen($cm[0][0]);
                        } else {
                            $pos = $len;
                        }
                        continue;
                    }
                    $cur = [
                        'members' => [],
                        'classes' => [],
                        'startLine' => $base + substr_count($text, "\n", 0, $bodyStart),
                        'endLine' => $base + substr_count($text, "\n", 0, $bodyStart),
                    ];
                    $pos = $bodyStart;
                }
                if (preg_match('/<\/script\b/i', $text, $cm, PREG_OFFSET_CAPTURE, $pos) === 1) {
                    $close = $cm[0][1];
                    self::pushTextSpan($cur, $stmt, $pos, $close, $base);
                    $endLine = $base + substr_count($text, "\n", 0, $close);
                    if ($endLine > $cur['endLine']) {
                        $cur['endLine'] = $endLine;
                    }
                    if ($cur['members'] !== []) {
                        $windows[] = $cur;
                    }
                    $cur = null;
                    $pos = $close + \strlen('</script');
                    continue;
                }
                // No closer in this run: everything left of the run
                // belongs to the window, which STAYS OPEN into the next
                // member (the ?-escape spanning that is the whole point).
                self::pushTextSpan($cur, $stmt, $pos, $len, $base);
                $lastLine = $base + substr_count($text, "\n", $pos, $len - $pos);
                if ($lastLine > $cur['endLine']) {
                    $cur['endLine'] = $lastLine;
                }
                break;
            }
        }
        // $cur !== null here means an unclosed window at sequence end:
        // DISCARDED (v1 law above).
        return $windows;
    }

    /**
     * Append [from, to) of one raw run to the open window: whole-run fast
     * path keeps the original node ('markup'); cut pieces become synthetic
     * position-marked InlineHTML slices ('slice'), line numbers carried
     * through substr_count so a warm re-parse recomputes them identically.
     *
     * @param array{members: list<Node\Stmt>, classes: list<string>, startLine: int, endLine: int} $cur
     */
    private static function pushTextSpan(array &$cur, Stmt\InlineHTML $run, int $from, int $to, int $base): void
    {
        if ($to <= $from) {
            return;
        }
        $value = $run->value;
        if ($from === 0 && $to === strlen($value)) {
            $cur['members'][] = $run;
            $cur['classes'][] = 'markup';
            return;
        }
        $offset = $from;
        while ($offset < $to) {
            $end = min($offset + self::SCRIPT_TEXT_WINDOW_BYTES, $to);
            $piece = substr($value, $offset, $end - $offset);
            $startLine = $base + substr_count($value, "\n", 0, $offset);
            $endLine = $base + substr_count($value, "\n", 0, $end);
            $cur['members'][] = new Stmt\InlineHTML($piece, [
                'startLine' => $startLine,
                'endLine' => $endLine,
                self::HTML_SLICE_ATTR => true,
            ]);
            $cur['classes'][] = 'slice';
            $offset = $end;
        }
    }

    /**
     * Item 7.2b: per-member lineage classes of a fold ('markup' | 'slice'
     * | 'stmt'), positionally parallel to the wrapper statement list. The
     * loader re-derives these from a fresh parse to POSITION-MATCH a warm
     * block ({@see Block::$fragmentLineage}).
     *
     * @param list<Node> $members
     * @return list<string>
     */
    public static function lineageClasses(array $members): array
    {
        $classes = [];
        foreach ($members as $m) {
            if ($m instanceof Stmt\InlineHTML) {
                $classes[] = $m->getAttribute(self::HTML_SLICE_ATTR, false) === true ? 'slice' : 'markup';
            } else {
                $classes[] = 'stmt';
            }
        }
        return $classes;
    }

    /**
     * Weight of a raw-text run: one pseudo-node per
     * SCRIPT_PSEUDO_NODE_BYTES bytes (floor 1 — a non-empty run is never
     * free), so HTML and statements share one weight currency for the
     * min/maxSize gates and WindowPacker.
     */
    private static function markupWeight(string $value): int
    {
        $len = strlen($value);
        if ($len <= 0) {
            return 0;
        }
        return max(1, (int) ceil($len / self::SCRIPT_PSEUDO_NODE_BYTES));
    }

    /**
     * Fold weight: 1 (the wrapper) plus every member under $stmtWeight —
     * InlineHTML members by {@see self::markupWeight()}, the rest through
     * the caller's seam (visitor memo at the mint, fresh nodeCount in
     * {@see self::htmlScriptWindows()}; both equal the same number).
     *
     * @param list<Node\Stmt> $members
     */
    public static function sequenceWeight(array $members, \Closure $stmtWeight): int
    {
        $w = 1;
        foreach ($members as $m) {
            $w += $m instanceof Stmt\InlineHTML
                ? self::markupWeight($m->value)
                : $stmtWeight($m);
        }
        return $w;
    }

    /**
     * Item 7.2b: the GATE-FREE window set of a file — every inline
     * &lt;script&gt; window its statement sequences carry, independent of
     * minSize/maxSize/allowedKinds. extract()'s mint leg and
     * BlockAstLoader's html_script relocation both see this SAME assembly
     * (visitor §5c lists + fragment buckets + scanScriptWindows), so a
     * fresh parse re-derives byte-identical wrappers for the loader's
     * positional match. One throwaway collection walker per relocation —
     * the sentinel roster blocks all minting and every size seam (the
     * kind-gate returns before the count line), keeping it pure
     * collection at list-walk cost.
     *
     * @param list<Node\Stmt> $topStmts
     * @return list<array{members: list<Node\Stmt>, classes: list<string>, startLine: int, endLine: int, namespace: ?string, class: ?string, weight: int}>
     */
    public static function htmlScriptWindows(string $file, array $topStmts): array
    {
        $traversable = [];
        foreach ($topStmts as $stmt) {
            if (CacheBlob::exceedsDepth([$stmt])) {
                continue; // same depth-prune extract() applies
            }
            $traversable[] = $stmt;
        }
        $visitor = new BlockVisitor(
            $file,
            0,
            PHP_INT_MAX,
            ['@no-mint@'],
            static function (Block $b): void {},
            true,
            true,
        );
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($traversable);

        $sequences = [];
        foreach ($visitor->stmtLists as $list) {
            $sequences[] = [
                'stmts' => $list['stmts'],
                'namespace' => $list['namespace'],
                'class' => $list['class'],
            ];
        }
        foreach (self::groupTopLevelFragments($topStmts) as $group) {
            $sequences[] = [
                'stmts' => $group['stmts'],
                'namespace' => $group['namespace'],
                'class' => null,
            ];
        }
        $stmtW = static fn (Node $n): int => AstSerializer::nodeCount($n);
        $out = [];
        foreach ($sequences as $seq) {
            foreach (self::scanScriptWindows($seq['stmts']) as $win) {
                $win['namespace'] = $seq['namespace'];
                $win['class'] = $seq['class'];
                $win['weight'] = self::sequenceWeight($win['members'], $stmtW);
                $out[] = $win;
            }
        }
        return $out;
    }
}

/** @internal */
final class BlockVisitor extends NodeVisitorAbstract
{
    private ?string $namespace = null;
    /** @var list<string> */
    private array $classStack = [];
    /** @var \Closure(Block):void */
    private \Closure $sink;
    /** @var SplObjectStorage<Node, int> */
    private SplObjectStorage $nodeCounts;
    /**
     * Oversized mass this visitor dropped (item 0.6): fully dropped
     * parents count once (unwindowable kinds, windowing off, or windowing
     * with nothing above the minSize floor salvaged), plus one per
     * statement a SALVAGED parent's window packing silently gave up on
     * (WindowPacker's droppedStatements). Read once by
     * BlockExtractor::extract() after the
     * traversal; a fresh visitor per file makes this a per-file count.
     */
    public int $oversizedDrops = 0;

    /**
     * Item 2.1 §5c: executable statement lists collected while walking
     * (only when $mineLists). One entry per list; the owner-kind tells
     * PreprocessWorker/SequenceIndex consumers WHAT the list is ('function',
     * 'method', 'closure', 'if-body', 'case-body', ..., '' for a top-level
     * fold bucket). Namespaces/classes are captured at ENTER time, matching
     * the block-mint context.
     *
     * @var list<array{stmts: list<Node\Stmt>, ownerKind: string, namespace: ?string, class: ?string}>
     */
    public array $stmtLists = [];

    /**
     * @param list<string> $allowedKinds
     */
    public function __construct(
        private readonly string $file,
        private readonly int $minSize,
        private readonly int $maxSize,
        private readonly array $allowedKinds,
        \Closure $sink,
        private readonly bool $windowOversized = true,
        private readonly bool $mineLists = false,
    ) {
        $this->sink = $sink;
        $this->nodeCounts = new SplObjectStorage();
    }

    #[\Override]
    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name?->toString();
        }
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_ || $node instanceof Stmt\Trait_ || $node instanceof Stmt\Enum_) {
            $this->classStack[] = $node->name?->toString() ?? '<anonymous>';
        }

        // Item 2.1: list collection rides THIS traversal (the §5c one-
        // walker law) — before the kind gate, since branch bodies (else/
        // catch/finally/case) are not classified kinds themselves but ARE
        // statement lists. Class-like bodies are deliberately NOT lists:
        // their members are declarations, each its own unit.
        if ($this->mineLists) {
            foreach ($this->statementListsOf($node) as $listEntry) {
                $this->stmtLists[] = [
                    'stmts' => $listEntry['stmts'],
                    'ownerKind' => $listEntry['ownerKind'],
                    'namespace' => $this->namespace,
                    'class' => end($this->classStack) ?: null,
                ];
            }
        }

        $kind = $this->classifyKind($node);
        if ($kind === null) {
            return null;
        }
        if ($this->allowedKinds !== [] && !in_array($kind, $this->allowedKinds, true)) {
            return null;
        }

        $size = $this->nodeCount($node);

        // engine-057: 'arrow' joins the exemption. ALL_KINDS mints the
        // kind, and a small `fn` declaration is the same top-level
        // abstraction a closure is — it must not die on minSize while
        // its function/method/closure siblings pass.
        // Item 2.3: 'class' joins on the declaration-shape argument —
        // the doc's targets are often tiny (a VO or an interface is a
        // handful of nodes; its BODY mass is irrelevant, its shape is
        // the finding). Oversize: class is deliberately unwindowable
        // (class-like bodies are member declarations, not lists — the
        // item-1.2 window packer never sees it, so WindowPacker's
        // body test returns null) and falls into the once-counted drop.
        $isTopLevel = in_array($kind, ['function', 'method', 'closure', 'arrow', 'class'], true);
        if (!$isTopLevel && $size < $this->minSize) {
            return null;
        }
        if ($size > $this->maxSize) {
            // Items 0.6 + 1.2: oversized no longer means silently gone.
            // With windowing on, the node's statement body is cut into
            // ≤ maxSize windows (the emitWindowRuns docblock states the
            // packer law); a parent from which nothing is salvaged —
            // unwindowable kinds included — joins the drop counter ONCE,
            // and a salvaged parent adds one entry per statement its
            // packing silently gave up on — everything feeding
            // summary.blocks_oversized either way.
            if ($this->windowOversized) {
                $body = WindowPacker::windowableBody($node);
                if ($body !== null) {
                    $name = null;
                    if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
                        $name = $node->name->toString();
                    }
                    $pack = WindowPacker::emitWindowRuns(
                        file: $this->file,
                        namespace: $this->namespace,
                        class: end($this->classStack) ?: null,
                        name: $name,
                        stmts: $body,
                        minSize: $this->minSize,
                        maxSize: $this->maxSize,
                        count: fn (Node $n): int => $this->nodeCount($n),
                        sink: $this->sink,
                    );
                    if ($pack['emitted'] > 0) {
                        // E2 r1 MINOR-1: the salvaged parent is not a drop,
                        // but statements its packing silently gave up on
                        // count individually — nothing invisible escapes.
                        $this->oversizedDrops += $pack['droppedStatements'];
                        return null;
                    }
                }
            }
            $this->oversizedDrops++;
            return null;
        }

        $name = null;
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            $name = $node->name->toString();
        } elseif ($node instanceof Stmt\ClassLike) {
            // Item 2.3: a class block is identified by its declaration
            // name (anonyma never classify, so ->name is non-null here
            // by the classifyKind contract).
            $name = $node->name->toString();
        }

        $start = $node->getStartLine();
        $end = $node->getEndLine();
        if ($start <= 0 || $end <= 0) {
            return null;
        }

        // The block's class field is the ENCLOSING class. For a class
        // block that is not the stack top (its own name was pushed at
        // enterNode before this mint) — it is the next frame down, so a
        // top-level declaration carries null and a nested one carries
        // its enclosing class. BlockAstLoader's index entries adopt the
        // identical convention (scopeCandidates matches the fields
        // verbatim).
        $stackDepth = count($this->classStack);
        $classField = $kind === BlockExtractor::CLASS_KIND
            ? ($stackDepth >= 2 ? $this->classStack[$stackDepth - 2] : null)
            : (end($this->classStack) ?: null);

        $block = new Block(
            file: $this->file,
            range: new LineRange($start, $end),
            kind: $kind,
            namespace: $this->namespace,
            class: $classField,
            name: $name,
            ast: $node,
        );
        $block->size = $size;
        ($this->sink)($block);
        return null;
    }

    #[\Override]
    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_ || $node instanceof Stmt\Trait_ || $node instanceof Stmt\Enum_) {
            array_pop($this->classStack);
        }
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = null;
        }
        return null;
    }

    /**
     * The statement lists a node OWNS for item 2.1 mining (doc: "function
     * bodies, loop and branch bodies, top level" — top-level buckets are
     * appended by BlockExtractor via the script fold map). Declaration
     * bodies, every conditional/loop body, every catch/finally/case body;
     * abstract/interface methods and empty bodies contribute nothing.
     *
     * @return list<array{stmts: list<Node\Stmt>, ownerKind: string}>
     */
    private function statementListsOf(Node $node): array
    {
        $out = [];
        $add = static function (?array $stmts, string $ownerKind) use (&$out): void {
            if ($stmts === null || $stmts === []) {
                return;
            }
            $out[] = ['stmts' => $stmts, 'ownerKind' => $ownerKind];
        };

        if ($node instanceof Stmt\Function_) {
            $add($node->getStmts(), 'function');
        } elseif ($node instanceof Stmt\ClassMethod) {
            $add($node->getStmts(), 'method');
        } elseif ($node instanceof Node\Expr\Closure) {
            $add($node->stmts, 'closure');
        } elseif ($node instanceof Stmt\If_) {
            $add($node->stmts, 'if-body');
            foreach ($node->elseifs as $elseif) {
                $add($elseif->stmts, 'elseif-body');
            }
            if ($node->else !== null) {
                $add($node->else->stmts, 'else-body');
            }
        } elseif ($node instanceof Stmt\While_) {
            $add($node->stmts, 'while-body');
        } elseif ($node instanceof Stmt\Do_) {
            $add($node->stmts, 'do-body');
        } elseif ($node instanceof Stmt\For_) {
            $add($node->stmts, 'for-body');
        } elseif ($node instanceof Stmt\Foreach_) {
            $add($node->stmts, 'foreach-body');
            // php-parser v5 removed foreach-else (the gramms removed the
            // rule): touching $node->else here minted an undefined-property
            // WARNING on every foreach list (failOnWarning breaks the 10.5
            // gate). There is no else arm to collect.
        } elseif ($node instanceof Stmt\TryCatch) {
            $add($node->stmts, 'try-body');
            foreach ($node->catches as $catch) {
                $add($catch->stmts, 'catch-body');
            }
            if ($node->finally !== null) {
                $add($node->finally->stmts, 'finally-body');
            }
        } elseif ($node instanceof Stmt\Switch_) {
            foreach ($node->cases as $case) {
                $add($case->stmts, 'case-body');
            }
        }

        return $out;
    }

    /** Memoized per-extraction node count (public seam for the fragment leg). */
    public function countNodes(Node $node): int
    {
        return $this->nodeCount($node);
    }

    private function classifyKind(Node $node): ?string
    {
        if ($node instanceof Stmt\Function_)    return 'function';
        if ($node instanceof Stmt\ClassMethod)  return 'method';
        if ($node instanceof Node\Expr\Closure) return 'closure';
        if ($node instanceof Node\Expr\ArrowFunction) return 'arrow';
        if ($node instanceof Stmt\If_)          return 'if';
        if ($node instanceof Stmt\For_)         return 'for';
        if ($node instanceof Stmt\Foreach_)     return 'foreach';
        if ($node instanceof Stmt\While_)       return 'while';
        if ($node instanceof Stmt\Do_)          return 'do';
        if ($node instanceof Stmt\TryCatch)     return 'try';
        if ($node instanceof Stmt\Switch_)      return 'switch';
        if ($node instanceof Node\Expr\Match_)  return 'match';
        // Item 2.3: the class-declaration kind, minted ONLY under an
        // explicit roster (CLASS_KIND's docblock states why the
        // never-implicit law lives at the mint, not at the :615 gate —
        // the empty-roster accept-all stance would leak class blocks
        // into every default run). NAMED ClassLikes only: an anonymous
        // class has no declaration identity to twin against.
        if ($node instanceof Stmt\ClassLike && $node->name !== null
            && in_array(BlockExtractor::CLASS_KIND, $this->allowedKinds, true)
        ) {
            return BlockExtractor::CLASS_KIND;
        }
        return null;
    }

    /**
     * Returns the number of descendant nodes in $node.
     *
     * SSOT: delegates to AstSerializer::nodeCount() — the single canonical
     * implementation.  Results are cached in an SplObjectStorage keyed by
     * node identity, so repeated calls on the same node object return
     * instantly.  The cache lives on the BlockVisitor instance, which is
     * created fresh for each extract() call, so it is also per-extraction.
     */
    private function nodeCount(Node $node): int
    {
        if ($this->nodeCounts->offsetExists($node)) {
            return $this->nodeCounts[$node];
        }
        $count = AstSerializer::nodeCount($node);
        $this->nodeCounts[$node] = $count;
        return $count;
    }
}
