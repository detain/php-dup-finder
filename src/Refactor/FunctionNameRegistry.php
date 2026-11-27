<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;

/**
 * Cross-cluster function-name uniqueness (W4-B fix M3/M4).
 *
 * SignatureBuilder picks each cluster's function name in isolation — and
 * in parallel runs it runs inside forked RefactorWorkers, where no shared
 * in-memory state is possible. That leaves two shipped artifacts racing in
 * one shared namespace: ApplyExtractor/RefactorPatchReporter emit per-
 * cluster files under `namespace Refactored;`, so two clusters mining the
 * same verb+noun (array_key_exists ×3, is_array ×3, __construct ×4 on the
 * real src corpus) collide into a redeclare fatal the moment a human
 * requires two generated files together. The pre-5.4 posture was strictly
 * worse (109 identical `extractedFunction` files) — mining redistributed
 * the collision into plausible human names, it did not introduce it.
 *
 * The cure is one pass at the merge point, which is also the single
 * name-decision point in the post-worker flow: EVERY consumer
 * (ApplyExtractor::extractFunctionName, the patch declaration emitted from
 * `$cluster->signature`, the member-hint guidance, JSON/CLI display)
 * derives the name from `$cluster->signature`, so rewriting the signature
 * here — once, uniquely — resolves every surface in lockstep.
 *
 * Collision policy: first claim (in ranked report order) keeps the mined
 * name; the loser gains a suffix derived from its CONTENT cluster id —
 * never a list index — so the resolved name is reproducible across runs
 * and independent of how the list arrived here.
 *
 * Lifecycle law (P4/PipelineState family): the registry is constructed
 * fresh inside ReportStage::run() — ONE instance per report payload. It
 * MUST NOT be made static or cached across cycles: Watch and Server re-run
 * the pipeline in the same process, and cross-run claims would smuggle
 * another cycle's ordering into this payload's names.
 *
 * Known gap (review n-2, disclosed not wired): the `phpdup serve` JSON
 * path builds its payload without running ReportStage, so Server responses
 * carry neither this name resolution nor the 5.3 placement advice. Wiring
 * the server plumbing is a separate work item (see wave-5 HANDOFF).
 *
 * once-111 (step once-S88) extends the lifecycle law OUTWARD: because the
 * claims are per-process memory, a second real apply over an output root
 * an earlier apply already populated re-mints names the earlier
 * generation cannot see — the cross-run collision class {@see
 * self::applyRootOccupancy()} exists to refuse.
 */
final class FunctionNameRegistry
{
    /** @var int Hex characters of the cluster id used for the first suffix tier. */
    private const SHORT_ID = 8;

    /**
     * Name RefactorPatchReporter writes its F1b summary under — restated as
     * a literal (not imported) to keep Refactor free of a reporting-class
     * dependency. The occupancy pins key the probe's map through
     * {@see \Phpdup\Reporting\RefactorPatchReporter::APPLY_SUMMARY_FILE},
     * and the CLI cross-run leg proves the two spellings agree end-to-end.
     */
    private const EARLIER_APPLY_SUMMARY = 'apply-summary.md';

    /** @var array<string, string> resolved name → claiming cluster id */
    private array $claims = [];

    /**
     * Resolve every cluster's function name to a run-unique one.
     *
     * @param list<Cluster> $clusters in final ranked report order
     * @return int number of clusters renamed (0 = corpus was already unique)
     */
    public function resolve(array $clusters): int
    {
        $renamed = 0;
        foreach ($clusters as $c) {
            if ($c->signature === null) {
                continue; // no extraction proposed, nothing to name
            }
            $name = self::nameOf($c->signature);
            if ($name === null) {
                continue; // signature text we cannot parse; consumers keep their own fallbacks
            }
            $owner = $this->claims[$name] ?? null;
            if ($owner === null || $owner === $c->id) {
                // First claim wins verbatim; re-resolving a payload this
                // instance already named is a no-op (idempotent pass).
                $this->claims[$name] = $c->id;
                continue;
            }
            $unique         = $this->uniqueName($name, $c->id);
            $c->signature   = self::withName($c->signature, $unique);
            $this->claims[$unique] = $c->id;
            $renamed++;
        }
        return $renamed;
    }

    /** Extract the function name a signature declares (single parsing truth). */
    public static function nameOf(string $signature): ?string
    {
        return preg_match('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $signature, $m) === 1
            ? $m[1]
            : null;
    }

    /** Swap the declared name inside a signature, preserving the rest verbatim. */
    public static function withName(string $signature, string $name): string
    {
        return (string) preg_replace(
            '/^function\s+[a-zA-Z_][a-zA-Z0-9_]*/',
            'function ' . $name,
            $signature,
            1,
        );
    }

    /**
     * Claimed names → original cluster ids (inspection/tests only).
     *
     * @return array<string,string>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    /**
     * once-S88 (php-dup-finder-once-111): occupancy probe for the ARMED
     * apply root — does this directory already hold an earlier real-apply
     * generation?
     *
     * Why the probe lives on this class: the hazard that makes root reuse
     * unsafe is exactly the Lifecycle law above — claims are per-process
     * memory, never persisted — so a second real apply cannot see the
     * names the first generation minted. Those {@see ApplyExtractor}
     * artifacts under `Refactored/` are never swept (once-S65: the apply
     * legs are deliberately unswept because an apply output dir holds the
     * extractor's rewritten project tree), so a re-mint lands beside
     * orphaned declarations, and member files restored from `backup/`
     * would have their `.orig` originals flattened by the second apply's
     * fresh backups.
     *
     * Only STRONG markers are named — things that exist solely after a
     * real (--dry-run=false) apply: the terminal
     * {@see self::EARLIER_APPLY_SUMMARY} artifact (every armed branch
     * writes it, even the all-skipped run), the generated-artifact tree
     * name (a symbolic link wearing it is reported as the escape hazard
     * it is, never scanned through), and a backup directory holding
     * `.orig` originals — or one that cannot be listed, which fails
     * CLOSED. The F1a preview's `apply.diff` is deliberately NOT a
     * marker: re-running the preview over the same root is the documented
     * work loop (the Command.php cross-run gate exempts the dry posture),
     * and since once-S88 the diff lands atomically (tmp+rename), so an
     * overwrite is always a whole-file replace. One directory level, no
     * recursion, no content reads.
     *
     * @return array<string,string> marker path => reason it proves an earlier apply (empty = root is free)
     */
    public static function applyRootOccupancy(string $applyRoot): array
    {
        $occupied = [];

        $summary = $applyRoot . DIRECTORY_SEPARATOR . self::EARLIER_APPLY_SUMMARY;
        if (is_link($summary)) {
            $occupied[$summary] = 'a symbolic link wearing the apply-summary name (writes would follow it out of the root)';
        } elseif (is_file($summary)) {
            $occupied[$summary] = 'apply summary of an earlier real apply';
        }

        $artifacts = $applyRoot . DIRECTORY_SEPARATOR . ApplyExtractor::ARTIFACT_NAMESPACE;
        if (is_link($artifacts)) {
            $occupied[$artifacts] = 'a symbolic link wearing the generated-tree name (writes would follow it out of the root)';
        } elseif (is_dir($artifacts)) {
            $occupied[$artifacts] = 'generated-artifact tree of an earlier real apply (its names are invisible to this run)';
        }

        $backup = $applyRoot . DIRECTORY_SEPARATOR . ApplyExtractor::BACKUP_SUBDIR;
        if (is_link($backup)) {
            $occupied[$backup] = 'a symbolic link wearing the backup-dir name (writes would follow it out of the root)';
        } elseif (is_dir($backup)) {
            $reason = self::backupHoldsOriginals($backup);
            if ($reason !== null) {
                $occupied[$backup] = $reason;
            }
        }

        return $occupied;
    }

    /**
     * Reason string when `$backup` proves an earlier apply's durable
     * originals (top-level `*.orig` images), or when it cannot be proven
     * empty at all; null when the directory is honestly empty.
     */
    private static function backupHoldsOriginals(string $backup): ?string
    {
        $diagnostic = '';
        set_error_handler(
            static function (int $severity, string $message) use (&$diagnostic): bool {
                if ($diagnostic === '') {
                    $diagnostic = $message;
                }
                return true;
            }
        );
        try {
            $entries = scandir($backup);
        } finally {
            restore_error_handler();
        }

        if (!is_array($entries)) {
            return 'backup directory exists but cannot be listed ('
                . ($diagnostic !== '' ? $diagnostic : 'no diagnostic recorded')
                . ') — the absence of .orig originals cannot be proven';
        }
        foreach ($entries as $entry) {
            if (
                $entry !== ''
                && !str_starts_with($entry, '.')
                && str_ends_with($entry, ApplyExtractor::BACKUP_SUFFIX)
            ) {
                return 'durable .orig backups of member files the earlier apply replaced';
            }
        }
        return null;
    }

    /**
     * Grow a content-derived suffix (short id → full id → sha256 of id)
     * until the candidate is unclaimed. Ids are content hashes, so two
     * clusters sharing a suffix prefix still separate on the longer tier.
     */
    private function uniqueName(string $name, string $clusterId): string
    {
        $short = (string) preg_replace('/[^A-Za-z0-9]/', '', $clusterId);
        $tiers = [];
        for ($take = self::SHORT_ID; strlen($short) > $take; $take += self::SHORT_ID) {
            $tiers[] = substr($short, 0, $take);
        }
        $tiers[] = $short;
        $tiers[] = hash('sha256', $clusterId);
        foreach (array_unique($tiers) as $suffix) {
            $candidate = $name . '_' . $suffix;
            if (!isset($this->claims[$candidate])) {
                return $candidate;
            }
        }
        // Practically unreachable: the tiers are content-derived and the
        // final one is a hash of the id itself.
        throw new \LogicException("FunctionNameRegistry could not mint a unique name for {$name} (cluster {$clusterId})");
    }
}
