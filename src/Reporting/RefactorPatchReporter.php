<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Refactor\FunctionNameRegistry;
use Phpdup\Refactor\ScriptKindApplyRefusal;
use Phpdup\Util\ControlText;

/**
 * Emits one .patch file per cluster — a heuristic, **manual-review-required**
 * unified diff that:
 *
 *   1. Adds a new file `Refactored/<ClusterId>.php` containing the suggested
 *      abstraction signature plus a `// TODO: implement` body.
 *   2. Replaces each member's body with a comment pointing at the new
 *      abstraction (so the diff is reviewable, not blindly applicable).
 *
 * Bails to a "manual review" header when:
 *   - the cluster has no signature (anti-unification didn't yield one), or
 *   - any member is AST-verified unsafe — uses `$this`, `self::`/`static::`/
 *     `parent::`, `yield`, a nested closure/arrow fn, by-ref bindings,
 *     static locals, `global`, or a `break`/`continue` that outruns its
 *     enclosing construct (mechanical replacement is unsafe — the
 *     abstraction would lose context), or
 *   - a member cannot be verified at all (unreadable, unparseable, or absent
 *     from its recorded span) — output-008: verification failure fails
 *     CLOSED with an explicit unverified reason, never silently safe.
 *
 * The reporter is intentionally conservative — its goal is to give
 * reviewers a starting point, not to ship working refactors.
 *
 * output-038/-043: member sources read through the shared per-run
 * {@see SourceTextStore} (single read path, symlink confinement), and the
 * unsafe verdict is memoized per cluster — the F1b branch used to run
 * detectUnsafe twice for every flagged cluster (once at the gate, once
 * again inside the fallback `buildPatch`), each run re-reading every
 * member's file uncached.
 *
 * output-022: the synthetic hunk header is COMPUTED from the emitted
 * added lines (the old literal `+1,12` lied for every shape — 13 real
 * lines for a single-line signature, more for multi-line ones — making
 * the whole diff malformed). The F1b human summary lands as its own
 * `apply-summary.md` artifact instead of masquerading under the dry
 * branch's `apply.diff` name, and every write goes through the
 * sanctioned WritesReportFile trait (mkdir + write in one place).
 *
 * output-004 (half B): the F1b tally is honest — a cluster counts
 * Applied only when {@see ApplyExtractor::apply()} returns a non-empty
 * list of member files it rewrote and verified byte-for-byte inside its
 * write-window transaction; zero-landed clusters ride Skipped, and the
 * summary's verified-member-file line reports the sum of that list.
 *
 * output-008 (S50): the unsafe gate is an AST analysis. Verdicts come from
 * the member's loaded AST when the extractor left one on the block, else
 * from a lazy re-parse of the confined {@see SourceTextStore} bytes located
 * by the same kind+span+name identity ApplyExtractor re-extracts with — the
 * gate rules on exactly the node the apply path would hoist. The old regex
 * checks missed bare `$this` uses (`Closure::bind($this, …)` passed the
 * gate and fatals at run time inside the hoisted function) and false-flagged
 * `$this->`/`self::` inside comments and strings. Text scanning survives
 * only demoted: it appends suspect names to an unverified fail-closed
 * reason and can never clear a member.
 */
final class RefactorPatchReporter
{
    use WritesReportFile;

    /** F1a dry-run artifact (php-dup-finder-output-022): a scaffold unified diff. */
    public const APPLY_DIFF_FILE = 'apply.diff';

    /** F1b apply artifact (php-dup-finder-output-022): a human summary, markdown. */
    public const APPLY_SUMMARY_FILE = 'apply-summary.md';

    /**
     * Hazards that refuse mechanical hoisting (php-dup-finder-output-008),
     * in report order — the FIRST one a member shows wins. The first four
     * strings are byte-identical to the pre-AST gate's verdicts so existing
     * patch text and pins keep their wording; the last four close the holes
     * the regexes missed (by-ref semantics, static/global re-scoping,
     * break/continue retargeting once the block leaves its loop).
     */
    private const UNSAFE_REASONS = [
        'this' => 'member uses $this — needs context-aware extraction',
        'class' => 'member uses self::/static::/parent:: — class-bound',
        'yield' => 'member is a generator (yield)',
        'closure' => 'member declares a closure (capture analysis required)',
        'byref' => 'member uses by-ref binding (reference semantics are lost on hoist)',
        'static' => 'member declares static local state (hoisting forks the store)',
        'global' => 'member touches global state (hoisting re-scopes it)',
        'escape' => 'member jumps out of the hoisted block (break/continue retargets on extraction)',
    ];

    /** output-038 memo: one detectUnsafe verdict per Cluster object. */
    /** @var array<int,string|null> spl_object_id => verdict */
    private array $unsafeVerdicts = [];

    /** output-043 pin surface: verdicts actually computed (memo hits excluded). */
    private int $unsafeComputations = 0;

    /**
     * output-008: one lazy re-parse per confined file for members whose AST
     * the extractor left unloaded. Source bytes come from the store alone —
     * the reporter never opens the file itself, so symlink confinement keeps
     * governing every read.
     *
     * @var array<string,array{0: string, 1: ?list<Node\Stmt>}> file => [source, stmts|null]
     */
    private array $memberParseCache = [];

    public function __construct(private readonly ?SourceTextStore $sourceText = null) {}
    /**
     * Write one `.patch` file per cluster under `$dir`.
     *
     * @param Report $report
     * @param string $dir Output directory
     * @param bool $apply When true, write one cumulative artifact under $dir:
     *                    {@see self::APPLY_DIFF_FILE} in dry-run (F1a scaffold
     *                    patches) or {@see self::APPLY_SUMMARY_FILE} on real
     *                    apply (F1b human summary — distinct name AND content
     *                    kind per output-022).
     * @param bool $dryRun When true with apply=true: write apply.diff only (F1a behavior).
     *                    When false with apply=true: perform actual file extraction (F1b).
     */
    public function writeTo(Report $report, string $dir, bool $apply = false, bool $dryRun = true): void
    {
        // Item 1.3 law: refuse the whole report BEFORE writing anything if
        // any cluster carries a 'script' member. Applies to every surface —
        // plain .patch files, F1a dry-run, F1b real apply — because the
        // refusal is about AST-addressability, not one code path. Loud and
        // typed (exit 1 via the dispatch catch), never a silent per-cluster
        // skip: a partial patch set masquerading as the full one is the
        // misfeed class. (F1b's per-cluster catch(\Throwable)→skip-comment
        // would otherwise swallow the ApplyExtractor guard — this pre-scan
        // makes that moot by design.)
        foreach ($report->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                // output-011 (SEC-03 shape): a newline in a member path lets
                // the "# member[i] @ file:range" hint (or a raw diff header)
                // forge `+++ b/evil` stream lines — the audit proof was
                // `patch -p1` CREATING attacker-named files from this exact
                // output. Refuse the whole surface, loudly, before any file
                // or directory is created.
                ControlText::reject($member->file, 'RefactorPatchReporter', 'member file path');
                if ($member->kind === 'script') {
                    throw ScriptKindApplyRefusal::forCluster($cluster, 'RefactorPatchReporter');
                }
            }
        }

        $store = $this->sourceText ?? new SourceTextStore($report->config->paths);

        if ($apply) {
            if ($dryRun) {
                // F1a / preview mode: write apply.diff containing scaffold patches
                $parts = [];
                foreach ($report->clusters as $cluster) {
                    if (count($cluster->members) < 2) {
                        continue;
                    }
                    $safeId = Slug::safe($cluster->id);
                    $parts[] = $this->buildPatch($cluster, $safeId, $store);
                }
                $this->writeFile($dir . DIRECTORY_SEPARATOR . self::APPLY_DIFF_FILE, implode("\n", $parts));
            } else {
                // F1b / actual apply mode: extract and write real files
                $extractor = new ApplyExtractor();
                $skipped = [];
                $applied = [];
                $verifiedMemberFiles = 0;
                foreach ($report->clusters as $cluster) {
                    if (count($cluster->members) < 2) {
                        continue;
                    }
                    $safeId = Slug::safe($cluster->id);
                    $unsafe = $this->detectUnsafe($cluster, $store);
                    if ($unsafe !== null) {
                        // Unsafe cluster: fall back to preview patch. The
                        // verdict is memoized, so buildPatch reuses this
                        // computation instead of re-reading every member
                        // (output-043).
                        $skipped[] = "# cluster " . CsvReporter::defuseFormula($cluster->id) . ": {$unsafe}";
                        $skipped[] = $this->buildPatch($cluster, $safeId, $store);
                        continue;
                    }
                    if ($cluster->generalizedAst === null || $cluster->signature === null) {
                        $skipped[] = "# cluster " . CsvReporter::defuseFormula($cluster->id) . ": no signature";
                        continue;
                    }
                    try {
                        $memberFiles = $extractor->apply($cluster, $dir);
                        if ($memberFiles === []) {
                            // output-004 (half B) honest tallies: apply()
                            // returns EXACTLY the member files it rewrote
                            // and read back byte-for-byte inside its
                            // write-window transaction. A cluster that
                            // landed nothing (a hole-less cluster returns
                            // before the window ever opens) must never be
                            // counted Applied — that row would claim a
                            // refactor the tree never received.
                            $skipped[] = "# cluster " . CsvReporter::defuseFormula($cluster->id)
                                . ": nothing applied (no verified member rewrite)";
                            continue;
                        }
                        $applied[] = CsvReporter::defuseFormula("{$safeId}.php");
                        $verifiedMemberFiles += count($memberFiles);
                    } catch (\Throwable $e) {
                        $skipped[] = "# cluster " . CsvReporter::defuseFormula($cluster->id) . ": error - " . $e->getMessage();
                    }
                }
                // Write the F1b human summary (output-022: distinct name
                // AND content kind — this is not a diff, so it no longer
                // lands under the dry branch's apply.diff name). The
                // member-file line (output-004 half B) states what the
                // extractor VERIFIED on disk, not what it attempted.
                $summary = "# phpdup apply summary\n"
                    . "# Applied: " . count($applied) . " clusters\n"
                    . "# Verified member rewrites: " . $verifiedMemberFiles
                    . " files (each read back byte-for-byte after the transaction)\n";
                if ($applied !== []) {
                    $summary .= "# Files: " . implode(', ', $applied) . "\n";
                    // output-003: applied members call \Refactored\fn()
                    // fully qualified — they RESOLVE only once the
                    // generated files are loaded. apply() lands a
                    // bootstrap stub beside the artifacts inside its
                    // write window; the summary names the exact path so
                    // the load story is told, not silently skipped.
                    $summary .= '# Bootstrap: ' . str_replace('\\', '/', ApplyExtractor::bootstrapStubPath($dir))
                        . " — require_once it (or the listed files) to load the generated abstractions\n";
                }
                if ($skipped !== []) {
                    $summary .= "# Skipped:\n" . implode("\n", $skipped) . "\n";
                }
                $this->writeFile($dir . DIRECTORY_SEPARATOR . self::APPLY_SUMMARY_FILE, $summary);
            }
            return;
        }

        foreach ($report->clusters as $cluster) {
            if (count($cluster->members) < 2) {
                continue;
            }
            $safeId = Slug::safe($cluster->id);
            $file = $dir . DIRECTORY_SEPARATOR . $safeId . '.patch';
            $this->writeFile($file, $this->buildPatch($cluster, $safeId, $store));
        }
    }

    /**
     * Build the textual patch for one cluster.
     *
     * The patch is intentionally non-applicable: it adds an
     * abstraction skeleton plus per-member hint comments so a human
     * reviewer can wire up the call sites themselves.
     *
     * @param SourceTextStore|null $store shared per-run source store; a direct
     *                                    caller that omits it gets a transient
     *                                    one (no confinement roots, no reuse)
     */
    public function buildPatch(Cluster $cluster, ?string $safeId = null, ?SourceTextStore $store = null): string
    {
        // Boundary guard for direct callers (writeTo pre-scans the whole
        // report, but buildPatch is public and embeds every member path in
        // the "# member[i] @" hints): refuse an unsafe path here too.
        foreach ($cluster->members as $member) {
            ControlText::reject($member->file, 'RefactorPatchReporter', 'member file path');
        }

        $safeId ??= Slug::safe($cluster->id);
        $store    ??= new SourceTextStore();
        $unsafe   = $this->detectUnsafe($cluster, $store);
        // output-035 (S6): the `#`-comment rows are metadata and their text
        // values go through CsvReporter::defuseFormula; the `+`/`-` hunk
        // payload below (including the prefixed signature lines) is raw
        // patch structure and stays byte-faithful by the carve-out law.
        $header = "# phpdup refactor patch — cluster " . CsvReporter::defuseFormula($cluster->id) . "\n"
                . "# members: " . count($cluster->members)
                . ", impact: {$cluster->impact}, confidence: " . sprintf('%.2f', $cluster->confidence) . "\n";
        if ($unsafe !== null) {
            return $header
                 . "# *** MANUAL REVIEW REQUIRED ***\n"
                 . "# Mechanical replacement is unsafe: {$unsafe}\n"
                 . "# Use the diff/HTML reports to inspect each member, then\n"
                 . "# craft the abstraction by hand.\n";
        }
        if ($cluster->signature === null) {
            return $header . "# No signature synthesised — skip.\n";
        }

        $abstractionFile = "Refactored/{$safeId}.php";
        // output-022: the hunk's added-line count is COMPUTED from the
        // emitted payload (header + prefixed signature lines + body), so
        // multi-line signatures can no longer desync the header from the
        // bytes below it. The `+` rows are raw patch structure (see the
        // output-035 carve-out above) and stay byte-faithful.
        $added = [
            '+<?php',
            '+declare(strict_types=1);',
            '+',
            '+namespace ' . ApplyExtractor::ARTIFACT_NAMESPACE . ';',
            '+',
            '+/**',
            "+ * Auto-generated abstraction for phpdup cluster {$cluster->id}.",
            '+ * Signature inferred via anti-unification — REVIEW BEFORE MERGE.',
            '+ */',
        ];
        foreach (explode("\n", $cluster->signature) as $sigLine) {
            $added[] = '+' . $sigLine;
        }
        $added[] = '+' . '{';
        $added[] = '+    // TODO: implement using the bodies of members listed above';
        $added[] = '+' . '}';
        $patch = $header
               . "diff --git a/{$abstractionFile} b/{$abstractionFile}\n"
               . "new file mode 100644\n"
               . "--- /dev/null\n"
               . "+++ b/{$abstractionFile}\n"
               . '@@ -0,0 +1,' . count($added) . " @@\n"
               . implode("\n", $added) . "\n";

        // Per-member edit hints — replace body with a single call comment.
        // M4 fix: hint names the function the patch ACTUALLY declares (the
        // registry-resolved signature name), never a `f_{clusterId}` ghost
        // a human could not call. output-003 tightened the fallback: it is
        // now the SAME `f_{clusterId}` identity ApplyExtractor::apply()
        // declares for an unparseable signature (one name builder shared
        // by hint and extraction), so hint and applied bytes can never
        // point at different names.
        $declName = FunctionNameRegistry::nameOf((string) $cluster->signature) ?? ('f_' . $cluster->id);
        foreach ($cluster->members as $i => $member) {
            $patch .= $this->memberHint($member, $declName, $i);
        }
        return $patch;
    }

    /**
     * @return string|null A short reason why the cluster cannot be
     *         auto-patched, or null when mechanical replacement is OK.
     *
     * output-043: the verdict is memoized per cluster object, so the F1b
     * gate and the fallback buildPatch share one computation (and one set
     * of member reads) instead of paying twice per flagged cluster.
     *
     * output-008 (S50): every member must be VERIFIED safe. A member whose
     * AST cannot be resolved — file unreadable, unparseable, or absent from
     * its recorded kind+span — refuses the cluster with an explicit
     * unverified reason. The old gate fail-OPENED on IO: an unreadable
     * member was skipped and its cluster waved through, and its regexes
     * missed bare `$this` escapes the hoisted function fatals on.
     */
    private function detectUnsafe(Cluster $cluster, SourceTextStore $store): ?string
    {
        $key = spl_object_id($cluster);
        if (array_key_exists($key, $this->unsafeVerdicts)) {
            return $this->unsafeVerdicts[$key];
        }
        $this->unsafeComputations++;

        $verdict = null;
        foreach ($cluster->members as $m) {
            $reason = $this->memberVerdict($m, $store);
            if ($reason !== null) {
                $verdict = $reason;
                break;
            }
        }
        return $this->unsafeVerdicts[$key] = $verdict;
    }

    /**
     * Verdict for one member: the trusted AST the extractor left on the
     * block when there is one, else a re-parse of the confined store bytes
     * located by the member's recorded kind+span+name. A null return means
     * VERIFIED safe — unreachable without a resolvable AST.
     */
    private function memberVerdict(Block $member, SourceTextStore $store): ?string
    {
        if ($member->ast instanceof Node) {
            return $this->astVerdict($member->ast);
        }

        $file = $member->file;
        $lines = $store->lines($file);
        if ($lines === null) {
            return 'member source unreadable — unverified, fail closed ('
                 . ($store->reason($file) ?? 'unreadable') . ')';
        }

        $stmts = $this->stmtsFor($file, $lines);
        if ($stmts === null) {
            return 'member source unparseable — unverified, fail closed'
                 . $this->textRiskSuffix($member, $lines);
        }

        $node = (new NodeFinder())->findFirst(
            $stmts,
            fn (Node $n): bool => $this->nodeIsMember($n, $member)
        );
        if (!$node instanceof Node) {
            return 'member not found at its recorded span — unverified, fail closed'
                 . $this->textRiskSuffix($member, $lines);
        }
        return $this->astVerdict($node);
    }

    /**
     * Locate a member node the way {@see ApplyExtractor} does for
     * extraction: exact start+end line, kind, and the declared name when
     * the block records one — the gate rules on exactly the node the apply
     * path would hoist. A 'script' block matches nothing (kind table
     * default-false); writeTo()'s pre-scan refuses those before any verdict.
     */
    private function nodeIsMember(Node $node, Block $member): bool
    {
        if ($node->getStartLine() !== $member->range->start
            || $node->getEndLine() !== $member->range->end
        ) {
            return false;
        }
        if (!self::nodeMatchesKind($node, $member->kind)) {
            return false;
        }
        if ($member->name === null) {
            return true;
        }
        $name = match (true) {
            $node instanceof Node\Stmt\Function_ => $node->name->toString(),
            $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
            default => null,
        };
        return $name === $member->name;
    }

    /** Mirror of the kind table ApplyExtractor::matchesKind() locates with. */
    private static function nodeMatchesKind(Node $node, string $kind): bool
    {
        return match ($kind) {
            'function' => $node instanceof Node\Stmt\Function_,
            'method' => $node instanceof Node\Stmt\ClassMethod,
            'closure' => $node instanceof Node\Expr\Closure,
            'arrow' => $node instanceof Node\Expr\ArrowFunction,
            'if' => $node instanceof Node\Stmt\If_,
            'for' => $node instanceof Node\Stmt\For_,
            'foreach' => $node instanceof Node\Stmt\Foreach_,
            'while' => $node instanceof Node\Stmt\While_,
            'do' => $node instanceof Node\Stmt\Do_,
            'try' => $node instanceof Node\Stmt\TryCatch,
            'switch' => $node instanceof Node\Stmt\Switch_,
            'match' => $node instanceof Node\Expr\Match_,
            default => false,
        };
    }

    /**
     * Walk one member node for hazards; the verdict is the first risk in
     * UNSAFE_REASONS order. The member's own declaration is not a hazard:
     * a method IS the hoisted unit, so only NESTED closures/arrows and
     * jumps that outrun their enclosing construct are refused.
     */
    private function astVerdict(Node $root): ?string
    {
        /** @var array<string,true> $risks */
        $risks = [];
        $this->scanMember($root, $risks, 0, 0, true);
        foreach (self::UNSAFE_REASONS as $key => $reason) {
            if (isset($risks[$key])) {
                return $reason;
            }
        }
        return null;
    }

    /**
     * Single collecting walk (PatternRecognizer's getSubNodeNames() idiom —
     * pre-order, allocation-free).
     *
     * @param array<string,true> $risks
     */
    private function scanMember(?Node $node, array &$risks, int $breakDepth, int $continueDepth, bool $isRoot = false): void
    {
        if ($node === null) {
            return;
        }

        if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
            $risks['this'] = true;
        } elseif ($node instanceof Node\Name && $node->isSpecialClassName()) {
            $risks['class'] = true;
        } elseif ($node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom) {
            $risks['yield'] = true;
        } elseif (!$isRoot && ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)) {
            $risks['closure'] = true;
        } elseif ($node instanceof Node\Stmt\Static_) {
            $risks['static'] = true;
        } elseif ($node instanceof Node\Stmt\Global_) {
            $risks['global'] = true;
        }

        if (($node instanceof Node\Arg || $node instanceof Node\Param
                || $node instanceof Node\ClosureUse || $node instanceof Node\Stmt\Foreach_
                || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod
                || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)
            && $node->byRef === true
        ) {
            $risks['byref'] = true;
        }

        if ($node instanceof Node\Stmt\Break_) {
            $level = $this->jumpLevel($node->num);
            if ($level === null || $level > $breakDepth) {
                $risks['escape'] = true;
            }
        } elseif ($node instanceof Node\Stmt\Continue_) {
            $level = $this->jumpLevel($node->num);
            if ($level === null || $level > $continueDepth) {
                $risks['escape'] = true;
            }
        }

        if ($node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_
        ) {
            $breakDepth++;
            $continueDepth++;
        } elseif ($node instanceof Node\Stmt\Switch_) {
            // Switch catches break but not continue.
            $breakDepth++;
        } elseif ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction
        ) {
            // A jump can never cross a function boundary.
            $breakDepth = 0;
            $continueDepth = 0;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->{$name};
            if ($sub instanceof Node) {
                $this->scanMember($sub, $risks, $breakDepth, $continueDepth);
                continue;
            }
            if (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $this->scanMember($item, $risks, $breakDepth, $continueDepth);
                    }
                }
            }
        }
    }

    /**
     * Level a break/continue targets: plain jump is level 1, a literal is
     * its own level, a dynamic `break($n)` is unresolvable (null) and fails
     * closed as an escape.
     */
    private function jumpLevel(?Node\Expr $num): ?int
    {
        if ($num === null) {
            return 1;
        }
        if ($num instanceof Node\Scalar\Int_) {
            return max(1, $num->value);
        }
        return null;
    }

    /**
     * Number of detectUnsafe verdicts actually computed (memo hits do not
     * count). Public as the pin surface for the output-043 counter test.
     */
    public function detectUnsafeComputations(): int
    {
        return $this->unsafeComputations;
    }

    /**
     * Render a unified-diff hint pointing at one cluster member.
     *
     * @param string $funcName the declared abstraction's name for THIS
     *                         cluster, resolved from the same signature
     *                         the patch body emits (M4 parity).
     */
    private function memberHint(Block $member, string $funcName, int $idx): string
    {
        // Metadata hint row (output-035): the member path is scanned text —
        // defused; the range digits are structural.
        $loc = CsvReporter::defuseFormula($member->file) . ":{$member->range->start}-{$member->range->end}";
        return "\n# member[{$idx}] @ {$loc}\n"
             // output-003: emitted through the SAME name builder the
             // applied call node is built from — the hint text and the
             // rewritten call site are byte-identical by construction.
             . "#   replace body with: return " . ApplyExtractor::qualifiedCallName($funcName) . "(...);\n";
    }

    /**
     * Lazily parse a confined file's store bytes, memoized per reporter
     * (one parse per file even for multi-member clusters). The bytes are
     * always the store's — output-016's single-read path keeps its promise:
     * this class opens no file, so symlink confinement governs every read.
     *
     * @param list<string> $lines
     * @return ?list<Node\Stmt> null when the source does not parse
     */
    private function stmtsFor(string $file, array $lines): ?array
    {
        $code = implode("\n", $lines);
        $cached = $this->memberParseCache[$file] ?? null;
        if ($cached !== null && $cached[0] === $code) {
            return $cached[1];
        }
        $stmts = (new AstParser())->parseCode($code);
        $this->memberParseCache[$file] = [$code, $stmts];
        return $stmts;
    }

    /**
     * Demoted text heuristics (output-008): they can no longer CLEAR a
     * member — every verdict that reaches them has already failed closed.
     * They survive only to name what the unresolvable bytes LOOK like they
     * contain, so a reviewer sees why the span smells hazardous. The range
     * slicing is the old memberSource() (output-016 provenance: this was
     * once a third uncached whole-file reader; it now rides store lines).
     *
     * @param list<string> $lines
     */
    private function textRiskSuffix(Block $member, array $lines): string
    {
        $start = max(0, $member->range->start - 1);
        $end   = min(count($lines), $member->range->end);
        $src = implode("\n", array_slice($lines, $start, $end - $start));

        $suspects = [];
        if (str_contains($src, '$this->')) {
            $suspects[] = '$this->';
        }
        if (preg_match('/\b(self|static|parent)::/', $src) === 1) {
            $suspects[] = 'self::/static::/parent::';
        }
        if (str_contains($src, 'yield')) {
            $suspects[] = 'yield';
        }
        if (preg_match('/\bfunction\s*\(/', $src) === 1) {
            $suspects[] = 'closure';
        }
        if ($suspects === []) {
            return '';
        }
        return ' (text scan names ' . implode(', ', $suspects)
             . ' — heuristic, unverified)';
    }
}
