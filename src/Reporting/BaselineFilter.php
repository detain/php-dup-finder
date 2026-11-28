<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Util\PathCanonical;

/**
 * The APPLY half of item 4.4: split a ranked cluster list into the
 * report's MAIN findings and the baseline APPENDIX (accepted findings
 * the team decided to keep, suppressed-with-acknowledgement).
 *
 * Suppression sources, in precedence order:
 *
 *  1. registry — the --baseline=<path> file's "dispositions" map (the
 *     durable team-decision surface: accepted/unresolved with
 *     provenance + timestamp; BaselineDisposition).
 *  2. directive — harvested `@phpdup-ignore <id-or-*> [reason]` rows
 *     (DeclaredTwinAnalyzer). An exact id matches only when the
 *     directive sits in one of the cluster's member files, so an id
 *     cannot be laundered across the tree; the `*` form is file-scoped
 *     and matches by file membership (drift-tolerant by design).
 *  3. config — the root-only phpdup.json "accepted" id list.
 *
 * Matching rides the STABLE finding ids the registry keying was built
 * for (a2854b6): id equality, never positional or index coupling.
 *
 * Fail-open resurface semantics (the doc's "re-accepted or removed,
 * never silently vanish"): a registry entry whose accepted id is no
 * longer current — its cluster's content drifted, so the id rotated —
 * keeps the live cluster in MAIN for re-review, and the appendix names
 * the rotation with 'resurfaced: true' joined by file provenance. An
 * accepted id whose provenance matches nothing this run is enumerated
 * as 'absent' rather than dropped. An 'unresolved' entry never
 * suppresses anything; it only shows triage state.
 *
 * When NO accepted source is in play (the default: no baseline file
 * dispositions, no accepted list, no directives) the split is a
 * no-op passthrough — the pipeline output is byte-unchanged.
 */
final class BaselineFilter
{
    /** @var list<string> */
    private array $scanRoots;

    private BaselineStore $store;

    private ?string $liveGitSha = null;

    private bool $gitShaProbed = false;

    /**
     * @param list<string> $scanRoots configured scan roots (provenance
     *                          canonicalization, shared with the store)
     */
    public function __construct(array $scanRoots = [])
    {
        $this->scanRoots = $scanRoots;
        $this->store = new BaselineStore($scanRoots);
    }

    /**
     * @param list<Cluster> $clusters ranked full set (gate/max-clusters
     *                          semantics stay coupled to this list — the
     *                          filter never mutates it)
     * @param array<string, BaselineDisposition> $registry file dispositions
     * @param list<string> $configIds phpdup.json "accepted" entries
     * @param list<array<string, mixed>> $directiveRows Report declared_twins
     *                          rows with kind === 'directive'
     * @return array{main: list<Cluster>, appendix: list<array<string, mixed>>}
     */
    public function split(array $clusters, array $registry, array $configIds, array $directiveRows): array
    {
        $directives = [];
        foreach ($directiveRows as $row) {
            if (($row['kind'] ?? null) === 'directive' && is_string($row['file'] ?? null)) {
                $directives[] = $row;
            }
        }

        $configLookup = array_flip(array_values($configIds));
        $currentIds = [];
        foreach ($clusters as $cluster) {
            $currentIds[$cluster->id] = true;
        }

        $main = [];
        $appendix = [];

        foreach ($clusters as $cluster) {
            $disposition = $registry[$cluster->id] ?? null;

            if ($disposition !== null && $disposition->isAccepted()) {
                $appendix[] = array_merge($this->row($cluster), [
                    'source' => 'registry',
                    'note' => $disposition->note,
                    'accepted_at' => $disposition->acceptedAt ?? date('c'),
                    'git_sha' => $disposition->gitSha ?? $this->liveGitSha(),
                ]);
                continue;
            }

            if ($disposition !== null) {
                // 'unresolved' registry entry: never suppress, but name
                // the pending triage state in the appendix.
                $appendix[] = [
                    'finding_id' => $cluster->id,
                    'status' => BaselineDisposition::STATUS_UNRESOLVED,
                    'source' => 'registry',
                    'resurfaced' => false,
                    'note' => $disposition->note,
                    'accepted_at' => $disposition->acceptedAt,
                    'members' => count($cluster->members),
                ];
                $main[] = $cluster;
                continue;
            }

            $directive = $this->matchDirective($cluster, $directives);
            if ($directive !== null) {
                $row = $this->row($cluster) + ['source' => 'directive'];
                $reason = trim($directive['reference'] ?? '');
                $row['note'] = $reason !== '' ? $reason : null;
                $row['directive_file'] = (string)$directive['file'];
                $row['directive_line'] = (int)$directive['line'];
                $appendix[] = $row;
                continue;
            }

            if (isset($configLookup[$cluster->id])) {
                $appendix[] = $this->row($cluster) + [
                    'source' => 'config',
                    'note' => 'accepted list in phpdup.json',
                ];
                continue;
            }

            $main[] = $cluster;
        }

        // Fail-open resurface scan over accepted registry entries the
        // report no longer carries an id for: file provenance joining a
        // surviving MAIN cluster means the duplication came back under a
        // new id. The cluster stays visible (fail-open) and the appendix
        // names the rotation.
        $mainFileIndex = $this->indexMainFiles($main);
        foreach ($registry as $findingId => $disposition) {
            if (!$disposition->isAccepted() || isset($currentIds[$findingId])) {
                continue;
            }

            $matched = [];
            foreach ($disposition->files as $canonicalFile) {
                foreach ($mainFileIndex[$canonicalFile] ?? [] as $hit) {
                    $matched[$hit['id']] = $hit;
                }
            }

            if ($matched !== []) {
                foreach ($matched as $hit) {
                    $appendix[] = [
                        'finding_id' => $hit['id'],
                        'accepted_id' => $findingId,
                        'status' => BaselineDisposition::STATUS_ACCEPTED,
                        'source' => 'registry',
                        'resurfaced' => true,
                        'note' => $disposition->note,
                        'accepted_at' => $disposition->acceptedAt,
                        'members' => $hit['members'],
                        'impact' => $hit['impact'],
                        'files' => $hit['files'],
                    ];
                }
                continue;
            }

            $appendix[] = [
                'finding_id' => $findingId,
                'status' => BaselineDisposition::STATUS_ACCEPTED,
                'source' => 'registry',
                'resurfaced' => false,
                'absent' => true,
                'note' => $disposition->note,
                'accepted_at' => $disposition->acceptedAt,
                'files' => $disposition->files,
            ];
        }

        usort(
            $appendix,
            static fn(array $a, array $b): int => strcmp((string)$a['finding_id'], (string)$b['finding_id']),
        );

        return ['main' => $main, 'appendix' => $appendix];
    }

    /**
     * Dispositions to persist when the operator writes a baseline
     * (--baseline-out / first-run auto-baseline): appendix rows whose
     * acceptance came from a directive or the config list THIS run —
     * registry-sourced rows already live in the file, and resurfaced
     * rows must not silently re-accept the drifted finding.
     *
     * @param list<array<string, mixed>> $appendix
     * @return list<array<string, mixed>>
     */
    public static function persistableRows(array $appendix): array
    {
        $rows = [];
        foreach ($appendix as $row) {
            // Only directive (source 'live') and config acceptances persist —
            // registry rows already live in the file; unresolved and resurfaced
            // rows never re-accept a drifted finding.
            $source = $row['source'] ?? null;
            $status = $row['status'] ?? null;
            $isAccepted = $status === BaselineDisposition::STATUS_ACCEPTED;
            if ($isAccepted && $source !== 'registry' && ($row['resurfaced'] ?? false) !== true) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $directives
     * @return array<string, mixed>|null
     */
    private function matchDirective(Cluster $cluster, array $directives): ?array
    {
        if ($directives === []) {
            return null;
        }

        $memberFiles = [];
        foreach ($cluster->members as $block) {
            $memberFiles[$block->file] = true;
        }

        foreach ($directives as $row) {
            $id = (string)($row['finding_id'] ?? '');
            if ($id === '' || !isset($memberFiles[(string)$row['file']])) {
                continue;
            }
            if ($id === '*' || $id === $cluster->id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<Cluster> $main
     * @return array<string, list<array{id: string, members: int, impact: int, files: list<string>}>>
     */
    private function indexMainFiles(array $main): array
    {
        $index = [];
        foreach ($main as $cluster) {
            $files = [];
            foreach ($cluster->members as $block) {
                $files[] = PathCanonical::underRoots($block->file, $this->scanRoots);
            }
            $entry = [
                'id' => $cluster->id,
                'members' => count($cluster->members),
                'impact' => $cluster->impact,
                'files' => array_values(array_unique($files)),
            ];
            foreach ($entry['files'] as $canonicalFile) {
                $index[$canonicalFile][] = $entry;
            }
        }
        return $index;
    }

    /**
     * Lazy once-only probe of the scan root's git HEAD for live
     * accept-time provenance (row() and the registry merge share it).
     */
    private function liveGitSha(): ?string
    {
        if (!$this->gitShaProbed) {
            $this->liveGitSha = $this->store->currentGitSha();
            $this->gitShaProbed = true;
        }
        return $this->liveGitSha;
    }

    /**
     * Shared accepted-row fields: identity + provenance captured fresh
     * this run (the file list and member hashes recompute against the
     * current scan, so the appendix always shows what exists NOW).
     *
     * @return array<string, mixed>
     */
    private function row(Cluster $cluster): array
    {
        $files = [];
        $mtimes = [];
        $hashes = [];
        foreach ($cluster->members as $block) {
            $canonical = PathCanonical::underRoots($block->file, $this->scanRoots);
            if (!in_array($canonical, $files, true)) {
                $files[] = $canonical;
                $mtime = $block->file !== '' ? @filemtime($block->file) : false;
                if ($mtime !== false) {
                    $mtimes[$canonical] = $mtime;
                }
            }
            $hashes[] = $this->store->computeBlockHash($block);
        }
        sort($hashes);

        return [
            'finding_id' => $cluster->id,
            'status' => BaselineDisposition::STATUS_ACCEPTED,
            'resurfaced' => false,
            'accepted_at' => date('c'),
            'git_sha' => $this->liveGitSha(),
            'members' => count($cluster->members),
            'impact' => $cluster->impact,
            'files' => $files,
            'mtimes' => $mtimes,
            'member_hashes' => $hashes,
        ];
    }
}
