<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Namespace_;
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
 * FIRST statement (namespace or class declaration) — never an extracted
 * Block — so the scan reads whole-file statement comment lists
 * (top-level statements and namespace children), not Block ranges.
 * Comments survive both AST cache round-trips (AstCache keys are
 * content hashes, so a hit is the identical file); the cache is
 * honoured read-only.
 *
 * Measurement rides the RETAINED ngram bags (no per-pair AST reload):
 * the claimant file's blocks against the referenced file's blocks,
 * pairwise Jaccard, best score wins. structuralHash equality is an
 * exact 1.0 hold. Below the run's similarity threshold the claim has
 * DRIFTED; an unresolvable reference is reported 'unresolved', never
 * silently dropped.
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
        private readonly ?string $cacheDir = null,
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

        $fileCluster = $this->fileClusterMap($clusters);

        $parser = new AstParser();
        $cache = $this->cacheDir !== null && is_dir($this->cacheDir) ? new AstCache($this->cacheDir) : null;

        $rows = [];
        foreach (array_keys($fileBlocks) as $file) {
            $statements = $this->loadStatements($parser, $cache, $file);
            if ($statements === null) {
                continue; // unparseable here — the pipeline already reports parse errors
            }
            foreach ($this->harvestClaims($file, $statements) as $claim) {
                $rows[] = $this->evaluate($claim, $fileBlocks, $fileCluster);
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
                    is_float($row['similarity']) ? number_format($row['similarity'], 4) : 'n/a',
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

    /** @return list<Stmt>|null */
    private function loadStatements(AstParser $parser, ?AstCache $cache, string $file): ?array
    {
        if ($cache !== null) {
            $cached = $cache->get($file);
            if ($cached !== null) {
                return $cached;
            }
        }
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        // Read-only use: the pipeline owns cache writes (PreprocessStage);
        // this pass never repopulates.
        return $parser->parseFile($file);
    }

    /**
     * The [f11]§4.3-trap cure: comment lists come off whole-file
     * statements — top-level statements, namespace children, and class
     * declarations — never off Block ranges (the namespace/class decl a
     * header comment attaches to is not an extracted kind).
     *
     * @param list<Stmt> $statements
     * @return list<array{file: string, kind: string, keyword: string, reference: string, line: int, comment: string, finding_id: string|null}>
     */
    private function harvestClaims(string $file, array $statements): array
    {
        $claims = [];
        $seenComments = [];

        $surfaces = [];
        foreach ($statements as $stmt) {
            $surfaces[] = $stmt;
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    $surfaces[] = $inner;
                }
            }
        }

        foreach ($surfaces as $surface) {
            $comments = $surface->getComments();
            $doc = $surface->getDocComment();
            if ($doc !== null) {
                $comments[] = $doc;
            }
            foreach ($comments as $comment) {
                $text = $comment->getText();
                $dedupe = $comment->getStartLine();
                if (isset($seenComments[$dedupe])) {
                    continue; // getDocComment() duplicates the trailing getComments() entry
                }
                $seenComments[$dedupe] = true;
                $line0 = $comment->getStartLine();

                if (preg_match_all(self::IGNORE_PATTERN, $text, $ignores, PREG_SET_ORDER)) {
                    foreach ($ignores as $match) {
                        $claims[] = [
                            'file' => $file,
                            'kind' => 'directive',
                            'keyword' => '@phpdup-ignore',
                            'reference' => trim($match[2]) !== '' ? trim($match[2]) : '',
                            'line' => $line0 + substr_count(substr($text, 0, (int) strpos($text, $match[0])), "\n"),
                            'comment' => $this->firstLine($text),
                            'finding_id' => $match[1],
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
        }
        return $claims;
    }

    /**
     * @param array{file: string, kind: string, keyword: string, reference: string, line: int, comment: string, finding_id: string|null} $claim
     * @param array<string, list<Block>> $fileBlocks
     * @param array<string, string>      $fileCluster
     * @return array<string, mixed>
     */
    private function evaluate(array $claim, array $fileBlocks, array $fileCluster): array
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

        $targetFile = $this->resolveReference($claim['reference'], array_keys($fileBlocks));
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
     * 'unresolved'.
     *
     * @param list<string> $files
     */
    private function resolveReference(string $reference, array $files): ?string
    {
        if (str_ends_with($reference, '.php')) {
            $needle = ltrim(strtr($reference, '\\', '/'), '/');
            $needleBase = basename($needle);
            $suffixHits = [];
            foreach ($files as $file) {
                $normalized = strtr($file, '\\', '/');
                if (basename($normalized) !== $needleBase) {
                    continue;
                }
                if ($normalized === $needle || str_ends_with($normalized, '/' . $needle)) {
                    return $file;
                }
                $suffixHits[$file] = true;
            }
            // A bare "X.php" mention: unique basename hit resolves; a
            // pathed mention whose directories differ does not.
            if (count($suffixHits) === 1 && !str_contains($needle, '/')) {
                return array_key_first($suffixHits);
            }
            return null;
        }

        $symbol = $reference;
        $symbol = rtrim($symbol, '()');
        $symbol = str_contains($symbol, '::') ? substr($symbol, (int) strpos($symbol, '::') + 2) : $symbol;
        $needle = strtolower(ltrim($symbol, '\\'));

        $lastSlash = strrpos($needle, '\\');
        $lastSegment = $lastSlash === false ? $needle : substr($needle, $lastSlash + 1);
        $haystacks = [];
        foreach ($files as $file) {
            $haystacks[strtolower(basename($file, '.php'))][$file] = true;
        }
        foreach ($this->index->all() as $block) {
            if ($block->name !== null && strtolower($block->name) === $lastSegment) {
                $haystacks[$lastSegment][$block->file] = true;
            }
            if ($block->class !== null && strtolower($block->class) === $lastSegment) {
                $haystacks[$lastSegment][$block->file] = true;
            }
        }
        $hits = $haystacks[$lastSegment] ?? [];
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
