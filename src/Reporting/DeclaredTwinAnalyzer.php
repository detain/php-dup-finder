<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Util\Num;

/**
 * Item 4.3 — the declared-twin checker ([f11]§4.3-design).
 *
 * The repo carries intent declarations in doc-comments. A claim is one
 * of the vocabulary keywords below followed, ON THE SAME LINE, by a
 * path (a `*.php` mention) or a class/method/function reference — the
 * reference is REQUIRED (improve_logic.md:289-292), so prose that
 * merely contains a word declares no twin. Vocabulary:
 *
 *   mirror / mirrored / mirrors
 *   twin / twins
 *   ported from
 *   keep in sync (with)
 *   copy of / copied from
 *   byte-identical (to)
 *   clone of
 *
 * Intent without verification is drift waiting to happen — this
 * analyzer measures each claim against the retained content and reports
 * the gap, INCLUDING false claims (pairs that drifted apart after the
 * comment was written).
 *
 * The [f11]§4.3-trap: a file-header comment attaches to the file's
 * FIRST statement — never an extracted Block — so the scan must read
 * comments off the whole file, not off Block ranges. output-S34
 * (php-dup-finder-output-017) moved that read to the token level: PHP's
 * own tokenizer hands over the comment stream directly, so a report run
 * deserializes no cached trees and re-parses nothing, with or without a
 * disk cache — one lex pass per corpus file, and claims are strictly
 * comment-sourced (a string literal that spells a claim declares
 * nothing).
 *
 * Measurement rides the RETAINED ngram bags (no per-pair reload):
 * the claimant file's blocks against the referenced file's blocks,
 * pairwise Jaccard, best score wins. structuralHash equality is an
 * exact 1.0 hold. Below the run's similarity threshold the claim has
 * DRIFTED; an unresolvable reference is reported 'unresolved', never
 * silently dropped.
 *
 * Reference resolution shares two maps built ONCE per run
 * (php-dup-finder-output-028): lowercase-path-basename => files for the
 * path branch, and lowercased symbol => files (file basenames plus
 * retained block names/classes) for the symbol branch. Claims stay rare
 * without costing O(corpus) each.
 *
 * Drifted claimants that are themselves clustered receive a
 * `declared-twin-drift` warning Finding (renders through the existing
 * architectural-findings surface in CLI/TUI/JSON). The full
 * claim-vs-reality table lands on Report::$declaredTwins (JSON:
 * top-level `declared_twins`, schema 1.3).
 *
 * The ignore-directive `@phpdup-ignore` plus id (plus optional reason)
 * is harvested in the same comment pass (item 4.4's registry input).
 * Applying them needs
 * Config/Command surfaces this lane must not touch — the harvest is
 * reported (`verdict: harvested`) and application is banked in HANDOFF.
 */
final class DeclaredTwinAnalyzer
{
    /**
     * Keyword + REQUIRED reference on the same line. Capture 1 is the
     * keyword phrase, capture 2 the reference (path | namespaced class |
     * Class::method | function()).
     */
    private const CLAIM_PATTERN = '~\b(mirror(?:ed|s|ing)?|twin(?:s|ed)?|ported\s+from|keep(?:s|ing)?\s+in\s+sync(?:\s+with)?|copy\s+of|copied\s+from|byte[\s-]identical(?:\s+to)?|clone\s+of)\b[^\n]{0,80}?((?:[A-Za-z0-9_.\-/\\\\]+\.php)|(?:\\\\?[A-Za-z][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+)+|[A-Za-z][A-Za-z0-9_]*::[A-Za-z0-9_]+|[a-zA-Z_][a-zA-Z0-9_]*\(\)))~iu';

    /** `@phpdup-ignore <finding-id-or-*> [reason]` — harvested for 4.4. */
    private const IGNORE_PATTERN = '/@phpdup-ignore\s+(\S+)(.*)/u';

    public function __construct(
        private readonly BlockIndex $index,
        private readonly float $similarityThreshold = 0.8,
    ) {
    }

    /**
     * Claim-vs-reality rows, sorted by (file, line) so the table is
     * byte-stable across scan orders.
     *
     * @param list<Cluster> $clusters
     * @return list<array<string, mixed>>
     */
    public function analyze(array $clusters): array
    {
        $fileBlocks = [];
        foreach ($this->index->all() as $block) {
            $fileBlocks[$block->file][] = $block;
        }
        ksort($fileBlocks);
        $files = array_keys($fileBlocks);

        // output-028: the two resolution indexes ride the same single
        // pass over the corpus that groups the blocks by file — built
        // once, consulted per claim.
        $pathIndex = [];
        $symbolIndex = [];
        foreach ($files as $file) {
            $pathIndex[basename(strtr($file, '\\', '/'))][$file] = true;
            $symbolIndex[strtolower(basename($file, '.php'))][$file] = true;
        }
        foreach ($fileBlocks as $blocks) {
            foreach ($blocks as $block) {
                if ($block->name !== null) {
                    $symbolIndex[strtolower($block->name)][$block->file] = true;
                }
                if ($block->class !== null) {
                    $symbolIndex[strtolower($block->class)][$block->file] = true;
                }
            }
        }

        $fileCluster = $this->fileClusterMap($clusters);

        $rows = [];
        foreach ($files as $file) {
            foreach ($this->harvestClaims($file) as $claim) {
                $rows[] = $this->evaluate($claim, $fileBlocks, $fileCluster, $pathIndex, $symbolIndex);
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
        return $rows;
    }

    /**
     * Surface drift where reviewers will see it: every drifted row whose
     * claimant file is clustered appends a warning Finding to that
     * cluster (after the analyzer loop has assigned the other findings).
     *
     * @param list<Cluster>                 $clusters
     * @param list<array<string, mixed>>    $rows
     */
    public function annotateClusters(array $clusters, array $rows): void
    {
        $byId = [];
        foreach ($clusters as $cluster) {
            $byId[$cluster->id] = $cluster;
        }

        $seen = [];
        foreach ($rows as $row) {
            if ($row['verdict'] !== 'drifted' || !is_string($row['cluster_id'])) {
                continue;
            }
            $dedupeKey = $row['cluster_id'] . '|' . ($row['target_file'] ?? '');
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $cluster = $byId[$row['cluster_id']] ?? null;
            if ($cluster === null) {
                continue;
            }
            $cluster->architecturalFindings[] = new Finding(
                analyzer: 'DeclaredTwinAnalyzer',
                code: 'declared-twin-drift',
                message: sprintf(
                    'doc-comment twin claim "%s" (line %d) drifted: best measured Jaccard %s against %s is below the %.2f threshold',
                    (string) $row['reference'],
                    (int) $row['line'],
                    (is_int($row['similarity']) || is_float($row['similarity']))
                        // Num::json collapses whole values to int at mint —
                        // accept both so a whole measured similarity still
                        // renders its number instead of 'n/a'.
                        ? number_format((float) $row['similarity'], 4) : 'n/a',
                    (string) ($row['target_file'] ?? 'the referenced file'),
                    $this->similarityThreshold,
                ),
                severity: Finding::SEVERITY_WARNING,
                suggestion: 're-sync the twin, or update/remove the doc-comment claim if the divergence is intentional',
            );
        }
    }

    /**
     * @param list<Cluster> $clusters
     * @return array<string, string> claimant file => first cluster id
     */
    private function fileClusterMap(array $clusters): array
    {
        $map = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster->members as $block) {
                if (!isset($map[$block->file])) {
                    $map[$block->file] = $cluster->id;
                }
            }
        }
        return $map;
    }

    /**
     * Token-level comment harvest (php-dup-finder-output-017): the
     * analyzer reads every comment the tokenizer yields — file headers,
     * declarations, and body comments alike — never a statement tree.
     * Multi-line comments keep occurrence-aware line numbering
     * (output-055): the row line is the comment's start line plus the
     * newlines before the match's byte offset inside it.
     *
     * @return list<array{file: string, kind: string, keyword: string, reference: string, line: int, comment: string, finding_id: string|null}>
     */
    private function harvestClaims(string $file): array
    {
        $claims = [];
        if (!is_file($file) || !is_readable($file)) {
            return $claims; // vanished/raced file — the pipeline already reports it
        }
        $code = @file_get_contents($file);
        if ($code === false) {
            return $claims;
        }

        foreach ($this->commentTokens($code) as [$line0, $text]) {
            if (preg_match_all(self::IGNORE_PATTERN, $text, $ignores, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($ignores as $match) {
                    // Occurrence-aware, mirroring the claim branch below
                    // (output-055): strpos() always reports the FIRST
                    // occurrence, so duplicate directive lines inside
                    // one comment all minted rows at the first line.
                    $claims[] = [
                        'file' => $file,
                        'kind' => 'directive',
                        'keyword' => '@phpdup-ignore',
                        'reference' => trim($match[2][0]),
                        'line' => $line0 + substr_count(substr($text, 0, (int) $match[0][1]), "\n"),
                        'comment' => $this->firstLine($text),
                        'finding_id' => $match[1][0],
                    ];
                }
            }

            if (preg_match_all(self::CLAIM_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $match) {
                    $claims[] = [
                        'file' => $file,
                        'kind' => 'claim',
                        'keyword' => strtolower(preg_replace('/\s+/u', ' ', $match[1][0]) ?? $match[1][0]),
                        'reference' => $match[2][0],
                        'line' => $line0 + substr_count(substr($text, 0, (int) $match[2][1]), "\n"),
                        'comment' => $this->firstLine($text),
                        'finding_id' => null,
                    ];
                }
            }
        }
        return $claims;
    }

    /**
     * The comment stream of one file as [startLine, text] pairs. The
     * tokenizer itself never throws on syntactically broken code (it
     * only classifies bytes); the catch is a belt for hostile inputs,
     * and "no claims" is its honest degradation — parsing is the
     * pipeline's job, reported elsewhere.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function commentTokens(string $code): array
    {
        try {
            $tokens = @token_get_all($code);
        } catch (\Throwable) {
            return [];
        }

        $comments = [];
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            $comments[] = [(int) $token[2], (string) $token[1]];
        }
        return $comments;
    }

    /**
     * @param array{file: string, kind: string, keyword: string, reference: string, line: int, comment: string, finding_id: string|null} $claim
     * @param array<string, list<Block>>               $fileBlocks
     * @param array<string, string>                    $fileCluster
     * @param array<string, array<string, true>>       $pathIndex
     * @param array<string, array<string, true>>       $symbolIndex
     * @return array<string, mixed>
     */
    private function evaluate(array $claim, array $fileBlocks, array $fileCluster, array $pathIndex, array $symbolIndex): array
    {
        $row = [
            'file' => $claim['file'],
            'keyword' => $claim['keyword'],
            'reference' => $claim['reference'],
            'kind' => $claim['kind'],
            'line' => $claim['line'],
            'comment' => $claim['comment'],
            'finding_id' => $claim['finding_id'],
            'target_file' => null,
            'target_blocks' => 0,
            'similarity' => null,
            'cluster_id' => $fileCluster[$claim['file']] ?? null,
            'verdict' => 'unresolved',
        ];

        if ($claim['kind'] === 'directive') {
            $row['verdict'] = 'harvested'; // application is banked (HANDOFF): never silently applied
            return $row;
        }

        $targetFile = $this->resolveReference($claim['reference'], $pathIndex, $symbolIndex);
        if ($targetFile === null) {
            return $row;
        }
        $row['target_file'] = $targetFile;
        $row['target_blocks'] = count($fileBlocks[$targetFile]);

        [$similarity, $verdict] = $this->measure($fileBlocks[$claim['file']], $fileBlocks[$targetFile]);
        $row['similarity'] = is_float($similarity) ? Num::json($similarity) : null;
        $row['verdict'] = $verdict;
        return $row;
    }

    /**
     * Path references match corpus files exactly or by (unique) suffix;
     * symbol references match block names / class names / file basenames
     * through the same unique-hit rule. Ambiguity stays honest as
     * 'unresolved'. Both branches are map lookups over the shared
     * per-run indexes (php-dup-finder-output-028) — the path branch only
     * touches same-basename candidates, never the whole corpus.
     *
     * @param array<string, array<string, true>> $pathIndex
     * @param array<string, array<string, true>> $symbolIndex
     */
    private function resolveReference(string $reference, array $pathIndex, array $symbolIndex): ?string
    {
        if (str_ends_with($reference, '.php')) {
            $needle = ltrim(strtr($reference, '\\', '/'), '/');
            $candidates = $pathIndex[basename($needle)] ?? [];
            $suffixHits = [];
            foreach (array_keys($candidates) as $file) {
                $normalized = strtr($file, '\\', '/');
                if ($normalized === $needle || str_ends_with($normalized, '/' . $needle)) {
                    return $file;
                }
                $suffixHits[$file] = true;
            }
            // A bare "X.php" mention: unique basename hit resolves; a
            // pathed mention whose directories differ does not.
            if (count($suffixHits) === 1 && !str_contains($needle, '/')) {
                return (string) array_key_first($suffixHits);
            }
            return null;
        }

        $symbol = $reference;
        $symbol = rtrim($symbol, '()');
        $symbol = str_contains($symbol, '::') ? substr($symbol, (int) strpos($symbol, '::') + 2) : $symbol;
        $needle = strtolower(ltrim($symbol, '\\'));

        $lastSlash = strrpos($needle, '\\');
        $lastSegment = $lastSlash === false ? $needle : substr($needle, $lastSlash + 1);
        $hits = $symbolIndex[$lastSegment] ?? [];
        return count($hits) === 1 ? (string) array_key_first($hits) : null;
    }

    /**
     * @param list<Block> $subject
     * @param list<Block> $target
     * @return array{0: ?float, 1: string}
     */
    private function measure(array $subject, array $target): array
    {
        $scorer = new JaccardSimilarity();
        $best = null;
        $comparable = false;

        foreach ($subject as $a) {
            foreach ($target as $b) {
                if ($a === $b) {
                    continue;
                }
                if ($a->structuralHash !== '' && $a->structuralHash === $b->structuralHash) {
                    return [1.0, 'holds']; // exact structural twins
                }
                $bagA = $a->ngramBag ?? [];
                $bagB = $b->ngramBag ?? [];
                if ($bagA === [] || $bagB === []) {
                    continue; // not comparable — never a fake zero
                }
                $comparable = true;
                $sim = $scorer->similarity($bagA, $bagB);
                if ($best === null || $sim > $best) {
                    $best = $sim;
                }
            }
        }

        if (!$comparable && $best === null) {
            return [null, 'unmeasurable'];
        }
        $similarity = round((float) $best, 4);
        return [$similarity, $similarity >= $this->similarityThreshold ? 'holds' : 'drifted'];
    }

    private function firstLine(string $comment): string
    {
        $raw = trim(explode("\n", $comment)[0] ?? '');
        $stripped = preg_replace('/^(?:\/\/|#|\/\*+\*?|#+)\s*/u', '', $raw) ?? $raw;
        return mb_substr(trim($stripped), 0, 200);
    }
}
