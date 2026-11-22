<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
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
 *   - any member uses `$this`/`self::`/`static::` / closure capture / yield
 *     (mechanical replacement is unsafe — the abstraction would lose context).
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
 */
final class RefactorPatchReporter
{
    use WritesReportFile;

    /** F1a dry-run artifact (php-dup-finder-output-022): a scaffold unified diff. */
    public const APPLY_DIFF_FILE = 'apply.diff';

    /** F1b apply artifact (php-dup-finder-output-022): a human summary, markdown. */
    public const APPLY_SUMMARY_FILE = 'apply-summary.md';

    /** output-038 memo: one detectUnsafe verdict per Cluster object. */
    /** @var array<int,string|null> spl_object_id => verdict */
    private array $unsafeVerdicts = [];

    /** output-043 pin surface: verdicts actually computed (memo hits excluded). */
    private int $unsafeComputations = 0;

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
            $src = $this->memberSource($m, $store);
            if ($src === null) {
                continue;
            }
            if (str_contains($src, '$this->')) {
                $verdict = 'member uses $this — needs context-aware extraction';
                break;
            }
            if (preg_match('/\b(self|static|parent)::/', $src) === 1) {
                $verdict = 'member uses self::/static::/parent:: — class-bound';
                break;
            }
            if (str_contains($src, 'yield')) {
                $verdict = 'member is a generator (yield)';
                break;
            }
            if (preg_match('/\bfunction\s*\(/', $src) === 1) {
                $verdict = 'member declares a closure (capture analysis required)';
                break;
            }
        }
        return $this->unsafeVerdicts[$key] = $verdict;
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
     * Read the source bytes for a member's range through the shared store,
     * returning null when the file is unreadable, gone, or refused by
     * symlink confinement. output-016: this used to be a third independent
     * uncached whole-file reader.
     */
    private function memberSource(Block $member, SourceTextStore $store): ?string
    {
        $lines = $store->lines($member->file);
        if ($lines === null) {
            return null;
        }
        $start = max(0, $member->range->start - 1);
        $end   = min(count($lines), $member->range->end);
        return implode("\n", array_slice($lines, $start, $end - $start));
    }
}
