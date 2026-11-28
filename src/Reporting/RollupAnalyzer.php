<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Normalization\SqlTableExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\ContainmentSimilarity;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Util\Num;
use Phpdup\Util\PathCanonical;

/**
 * Item 2.2 (improve_logic.md, filing f08): corpus-awareness rollups.
 *
 * The report side of a duplicate analysis answers "which clusters look
 * alike"; it never answered "how much of this corpus is even comparable,
 * how does one FILE mirror another, and which DIRECTORY pairs re-implement
 * themselves". That is what the corpus-awareness finding (f08 §1 — the
 * 1,438-of-20,789 private-corpus story whose numbers are owner-side done-
 * when evidence) asked for, and this class supplies the mechanism from
 * data the pipeline already holds:
 *
 *   - per-file token/AST facts (declarations by species, expression and
 *     call-site counts, SQL table references through
 *     {@see SqlTableExtractor}) — recomputed at report time over the
 *     scanned files, never persisted, so nothing new rides a cache key
 *     and no Block field is added;
 *   - per-file comparable lines as the interval UNION of every block
 *     range in the file (f08 §2: nested members must not sum — the
 *     Vps/Start example over-counted 74.5% naive against the true
 *     29/51 = 56.9% union), reusing {@see Cluster::unionLineSpan()};
 *   - twin FILE pairs, judged in both directions (twin(A→B) is the share
 *     of A's comparable lines that sit in clusters containing a B member;
 *     a file pair is a twin only when BOTH directions clear the threshold
 *     — one-sided containment is a partial mirror, not a twin), plus the
 *     clustering-independent seeding f08 §2 names: same-relative-path and
 *     same-basename seeds score their blocks directly on the RETAINED
 *     ngram bags when no shared cluster links them (the Vps/Start case:
 *     J=0.613 / C=0.840 clears the 0.5 twin bar even though the run's
 *     block thresholds did not cluster them), and seed pairs where one
 *     side has no blocks at all become honest declared-only rows rather
 *     than coverage lies;
 *   - mirrored DIRECTORY pairs: twin file pairs grouped by relative
 *     directory, kept at >= minFilesPerDirPair — the structural
 *     "module X is a copy of module Y" signal no cluster list shows.
 *
 * Demoted clusters (item 6.2's {@see Ranker::declarativeDemotion()},
 * factor < 1) are exempt from twin coverage numerators: a 55x
 * `toArray()` mega-cluster must not fake-inflate every file pair it
 * touches into a mirror (f08 §2 risk 1). The exclusion rides the
 * Ranker's PUBLIC verdict method — this analyzer never mutates clusters
 * and the rollup is a separate output, exactly per f08 §2.
 *
 * The call-site species set is the codebase precedent:
 * {@see \Phpdup\Semantic\CallGraph} walks MethodCall | StaticCall |
 * FuncCall (NullsafeMethodCall / PropertyFetch / New are not in that
 * set), which is the f08 letter's exact list.
 *
 * Cost is disclosed: one php-parser pass per file at report time,
 * file-at-a-time so memory stays bounded by the largest single file.
 * It is synchronous like the {@see DeclaredTwinAnalyzer} pass that
 * mounts beside it in ReportStage — the whole-corpus sweep is the item's
 * acknowledged price; a cooperative (chunk-yielded) leg is a named
 * follow-up, not a silent skip.
 *
 * Aggregate caps bound the payload (f08 §2 "per-file detail bounded"):
 * top-N lists are truncation-counted, never silently cut.
 *
 * CLI runs pass the scan roots so every emitted path is the scan-scoped
 * relative form ({@see PathCanonical::underRoots()}) — machine-portable
 * by construction, which is what lets the golden snapshots carry rollup
 * rows without a path normaliser (GoldenTest portability law).
 */
final class RollupAnalyzer
{
    /** f08 §2: the Vps/Start bar — both directions >= 0.5. */
    public const DEFAULT_TWIN_FILE_THRESHOLD = 0.5;
    /** f08 §2: a mirrored-directory row needs at least this many twin file pairs. */
    public const DEFAULT_MIN_FILES_PER_DIR_PAIR = 2;

    /** Payload caps (f08 §2 "per-file detail bounded"): top-N per list. */
    public const TOP_FILES = 20;
    public const TOP_PAIRS = 20;
    public const TOP_DIRS = 10;
    public const TOP_DECLARED = 10;

    /**
     * Hard bound on candidate file pairs evaluated per run. Past it the
     * lexicographically-last candidates are dropped and
     * totals.seed_candidates_dropped counts the loss — candidates are
     * sorted before truncation, so it is deterministic, never a coin flip.
     */
    public const SEED_PAIR_CAP = 4000;

    /**
     * Cap on the side-size of a direct bag comparison (seed pairs whose
     * blocks are not cluster-linked). The largest blocks are kept —
     * deterministic, and it bounds the O(k*m) worst case of an
     * identical-duplicate-directory scan.
     */
    public const COMPARE_SIDE_CAP = 16;

    /** Declaration species counted per file (f08 §2: function/method/class/trait/interface/enum). */
    public const DECLARATION_SPECIES = ['functions', 'methods', 'classes', 'interfaces', 'traits', 'enums'];

    /** Per-file fact keys that roll up into totals by plain summation. */
    private const SUM_KEYS = ['functions', 'methods', 'classes', 'interfaces', 'traits', 'enums', 'expressions', 'call_sites', 'sql_refs', 'sql_tableless', 'file_lines'];

    private readonly AstParser $parser;
    private readonly NodeFinder $finder;
    private readonly JaccardSimilarity $jaccard;
    private readonly ContainmentSimilarity $containment;
    private readonly Ranker $ranker;

    /**
     * @param list<string> $relRoots scan roots (Config::$paths) for the
     *                               relative-path form; files outside all
     *                               roots keep their separator-folded
     *                               literal (the PathCanonical honest
     *                               fallback).
     */
    public function __construct(
        public readonly float $twinFileThreshold = self::DEFAULT_TWIN_FILE_THRESHOLD,
        public readonly int $minFilesPerDirPair = self::DEFAULT_MIN_FILES_PER_DIR_PAIR,
        private readonly array $relRoots = [],
    ) {
        $this->parser = new AstParser();
        $this->finder = new NodeFinder();
        $this->jaccard = new JaccardSimilarity();
        $this->containment = new ContainmentSimilarity();
        // Only the PUBLIC item-6.2 verdict method is used; the ranking
        // dials are irrelevant to it, so the default-constructed instance
        // is the whole contract.
        $this->ranker = new Ranker();
    }

    /**
     * @param list<Cluster> $clusters the run's final cluster list (read-only here)
     * @param list<Block> $blocks every extracted block, clustered or not
     * @param list<string> $files the scanned file paths
     * @return array<string, mixed> the summary 'rollups' payload
     */
    public function analyze(array $clusters, array $blocks, array $files): array
    {
        // ---- 1. index blocks per disk file --------------------------------
        $blocksByKey = [];
        foreach ($blocks as $b) {
            $blocksByKey[PathCanonical::diskKey($b->file)][] = $b;
        }

        // ---- 2. cluster spans + demotion exemption ------------------------
        // block id -> cluster ids, and cluster id -> the distinct files it
        // spans. Demoted clusters enter NEITHER map, so their members'
        // ranges contribute nothing to any coverage numerator.
        $blockClusterIds = [];
        $clusterFiles = [];
        foreach ($clusters as $c) {
            if ($this->ranker->declarativeDemotion($c) < 1.0) {
                continue;
            }
            $fileKeys = [];
            foreach ($c->members as $m) {
                $blockClusterIds[$m->id][] = $c->id;
                $fileKeys[PathCanonical::diskKey($m->file)] = true;
            }
            $clusterFiles[$c->id] = array_keys($fileKeys);
        }

        // ---- 3. per-file comparable lines (interval union, never sum) ----
        $comparable = [];
        foreach ($blocksByKey as $key => $list) {
            $comparable[$key] = Cluster::unionLineSpan($list);
        }

        // ---- 4. per-file token facts (the report-time AST pass) ----------
        $fileSet = [];
        foreach ($files as $path) {
            $fileSet[PathCanonical::diskKey($path)] = true;
        }
        foreach (array_keys($blocksByKey) as $key) {
            $fileSet[$key] = true;
        }
        $facts = [];
        $allTables = [];
        $allCalls = [];
        foreach (array_keys($fileSet) as $path) {
            $f = $this->fileFacts($path);
            $facts[$path] = $f;
            $allTables += array_fill_keys($f['tables'], true);
            $allCalls += array_fill_keys($f['call_names'], true);
        }

        // ---- 5. machine-free display identity per file --------------------
        $display = [];
        foreach (array_keys($fileSet) as $key) {
            $display[$key] = $this->display($key);
        }

        // ---- 6. candidate pairs: cluster-linked + name seeded ------------
        $linked = [];
        foreach ($clusterFiles as $cid => $keys) {
            sort($keys);
            $n = count($keys);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $linked[$keys[$i] . "\0" . $keys[$j]][] = $cid;
                }
            }
        }
        // f08 §2 seeding, independent of clustering: (a) same relative
        // path (mirrored trees / parallel directories), (b) same basename
        // (renamed copies). Path pairs claim their token first — the
        // shared-reason recorded for a doubly-seeded pair is the stronger
        // statement ('path' implies mirrored layout, 'name' alone does not).
        $seedReason = [];
        foreach ($this->seedGroups($display, 'path') as $group) {
            foreach ($this->pairsOf($group) as [$x, $y]) {
                $seedReason[$x . "\0" . $y] = 'path';
            }
        }
        foreach ($this->seedGroups($display, 'name') as $group) {
            foreach ($this->pairsOf($group) as [$x, $y]) {
                $token = $x . "\0" . $y;
                if (!isset($seedReason[$token])) {
                    $seedReason[$token] = 'name';
                }
            }
        }

        $candidateKeys = array_values(array_unique(array_merge(array_keys($linked), array_keys($seedReason))));
        sort($candidateKeys);
        $candidatesDropped = 0;
        if (count($candidateKeys) > self::SEED_PAIR_CAP) {
            $candidatesDropped = count($candidateKeys) - self::SEED_PAIR_CAP;
            $candidateKeys = array_slice($candidateKeys, 0, self::SEED_PAIR_CAP);
        }

        // ---- 7. evaluate each candidate -----------------------------------
        $twinRows = [];
        $declaredRows = [];
        $directCompares = 0;
        foreach ($candidateKeys as $token) {
            /** @var array{0:string,1:string} $pair */
            $pair = explode("\0", $token);
            [$a, $b] = $pair;
            $ca = $comparable[$a] ?? 0;
            $cb = $comparable[$b] ?? 0;
            $clusterIds = $linked[$token] ?? [];
            sort($clusterIds);

            if ($ca === 0 || $cb === 0) {
                // No coverage denominator to divide by — the honest row is
                // the naming claim itself (f08: zero-block pairs become
                // declaration_only, never a 0% mirror). A cluster-linked
                // pair always has blocks on both sides by construction,
                // so this branch is seeding-only.
                if (isset($seedReason[$token])) {
                    $declaredRows[] = [
                        'a_root' => $display[$a]['root'], 'a_path' => $display[$a]['path'],
                        'b_root' => $display[$b]['root'], 'b_path' => $display[$b]['path'],
                        'shared' => $seedReason[$token],
                    ];
                }
                continue;
            }

            $covA = $this->linkedCoverage($a, $clusterIds, $blocksByKey, $blockClusterIds, $ca);
            $covB = $this->linkedCoverage($b, $clusterIds, $blocksByKey, $blockClusterIds, $cb);
            $twinLinked = $covA >= $this->twinFileThreshold && $covB >= $this->twinFileThreshold;
            $seedBest = 0.0;
            if (!$twinLinked) {
                // The seed-direct leg only runs when no shared cluster
                // carries the pair (f08 §2): the Vps/Start case where the
                // files never clustered together but their best blocks
                // compare above the twin bar.
                $seedBest = $this->directBest($blocksByKey[$a] ?? [], $blocksByKey[$b] ?? []);
                $directCompares++;
                if ($seedBest < $this->twinFileThreshold) {
                    continue;
                }
            }
            $twinRows[] = [
                'a_root' => $display[$a]['root'], 'a_path' => $display[$a]['path'],
                'b_root' => $display[$b]['root'], 'b_path' => $display[$b]['path'],
                'a_to_b' => Num::json($covA),
                'b_to_a' => Num::json($covB),
                'seed_best' => Num::json($seedBest),
                // The coverage metric states the claim that made the row:
                // the conservative min of the two linked directions, or the
                // direct bag score when seeding carried it alone.
                'coverage' => Num::json($twinLinked ? min($covA, $covB) : $seedBest),
                'linked_cluster_ids' => $clusterIds,
                // true only when the claim rests on the direct compare
                // alone — no shared cluster carries it (f08 row field).
                'seed_direct' => !$twinLinked,
            ];
        }

        // Deterministic twin order: conservative coverage desc, then both
        // display labels asc.
        usort($twinRows, static function (array $x, array $y): int {
            return [$y['coverage'], $x['a_path'], $x['a_root'], $x['b_path'], $x['b_root']]
                <=> [$x['coverage'], $y['a_path'], $y['a_root'], $y['b_path'], $y['b_root']];
        });

        // ---- 8. mirrored directory pairs (f08 §2 dir roll-up) ------------
        $dirGroups = [];
        foreach ($twinRows as $row) {
            $dirA = self::dirLabel($row['a_root'], $row['a_path']);
            $dirB = self::dirLabel($row['b_root'], $row['b_path']);
            if ($dirA === $dirB) {
                continue; // a mirror within one directory label is a
                         // copy-paste neighbourhood, not a mirrored-directory row
            }
            $key = min($dirA, $dirB) . "\0" . max($dirA, $dirB);
            $dirGroups[$key]['count'] = ($dirGroups[$key]['count'] ?? 0) + 1;
            $dirGroups[$key]['coverage'] = max($dirGroups[$key]['coverage'] ?? 0.0, (float) $row['coverage']);
            $dirGroups[$key]['dir_a'] = $dirA;
            $dirGroups[$key]['dir_b'] = $dirB;
        }
        $mirrored = [];
        foreach ($dirGroups as $g) {
            if ($g['count'] < $this->minFilesPerDirPair) {
                continue;
            }
            $mirrored[] = [
                'dir_a' => $g['dir_a'], 'dir_b' => $g['dir_b'],
                'file_pairs' => $g['count'],
                'coverage' => Num::json($g['coverage']),
            ];
        }
        usort($mirrored, static function (array $x, array $y): int {
            return [$y['coverage'], $x['dir_a'], $x['dir_b']] <=> [$x['coverage'], $y['dir_a'], $y['dir_b']];
        });

        // ---- 9. top files by comparable volume ----------------------------
        // Rows are the COMPARABLE corpus: only files that emitted at least
        // one block get a row — a zero-block file has no line density to
        // report. It still counts in totals.files, contributes its token
        // facts to the totals, and can carry declared_only pair rows: the
        // naming claim survives, the row set stays honest, and a scanned
        // but non-comparable file never surfaces as a path here.
        $fileOrder = array_keys($blocksByKey);
        usort($fileOrder, static function (string $x, string $y) use ($comparable, $facts, $display): int {
            // Volume desc, disk lines desc, then labels asc (tie positions
            // only compare once the numeric keys are equal).
            return [$comparable[$y] ?? 0, $facts[$y]['file_lines'], $display[$x]['path'], $display[$x]['root']]
                <=> [$comparable[$x] ?? 0, $facts[$x]['file_lines'], $display[$y]['path'], $display[$y]['root']];
        });
        $topFiles = [];
        foreach (array_slice($fileOrder, 0, self::TOP_FILES) as $key) {
            $topFiles[] = $this->fileRow($display[$key], $blocksByKey[$key] ?? [], $comparable[$key] ?? 0, $facts[$key]);
        }

        // ---- 10. totals ----------------------------------------------------
        $totals = array_fill_keys(self::SUM_KEYS, 0);
        $totals['declarations'] = 0;
        foreach ($facts as $f) {
            foreach (self::SUM_KEYS as $k) {
                $totals[$k] += $f[$k];
            }
            $totals['declarations'] += $f['functions'] + $f['methods'] + $f['classes']
                + $f['interfaces'] + $f['traits'] + $f['enums'];
        }
        $totals['files'] = count($fileSet);
        $totals['blocks'] = count($blocks);
        $totals['comparable_lines'] = array_sum($comparable);
        $totals['distinct_calls'] = count($allCalls);
        $totals['distinct_sql_tables'] = count($allTables);
        $totals['twin_file_pairs'] = count($twinRows);
        $totals['mirrored_directories'] = count($mirrored);
        $totals['declared_only_pairs'] = count($declaredRows);
        $totals['direct_comparisons'] = $directCompares;
        $totals['seed_candidates_dropped'] = $candidatesDropped;

        return [
            'totals' => $totals,
            'top_files' => $topFiles,
            'files_truncated' => max(0, count($blocksByKey) - self::TOP_FILES),
            'twin_pairs' => array_slice($twinRows, 0, self::TOP_PAIRS),
            'pairs_truncated' => max(0, count($twinRows) - self::TOP_PAIRS),
            'mirrored_directories' => array_slice($mirrored, 0, self::TOP_DIRS),
            'dirs_truncated' => max(0, count($mirrored) - self::TOP_DIRS),
            'declared_only_pairs' => array_slice($declaredRows, 0, self::TOP_DECLARED),
            'declared_only_truncated' => max(0, count($declaredRows) - self::TOP_DECLARED),
        ];
    }

    /**
     * Union of one file's blocks that sit in cluster-linked clusters,
     * divided by the file's comparable lines. Demoted clusters were
     * excluded from $blockClusterIds upstream, so their members' ranges
     * contribute nothing here (f08 risk 1).
     *
     * @param list<string> $clusterIds
     * @param array<string, list<Block>> $blocksByKey
     * @param array<string, list<string>> $blockClusterIds
     */
    private function linkedCoverage(string $fileKey, array $clusterIds, array $blocksByKey, array $blockClusterIds, int $denominator): float
    {
        if ($clusterIds === [] || $denominator === 0) {
            return 0.0;
        }
        $wanted = array_flip($clusterIds);
        $covered = [];
        foreach ($blocksByKey[$fileKey] ?? [] as $b) {
            foreach ($blockClusterIds[$b->id] ?? [] as $cid) {
                if (isset($wanted[$cid])) {
                    $covered[] = $b;
                    break;
                }
            }
        }
        if ($covered === []) {
            return 0.0;
        }
        return Cluster::unionLineSpan($covered) / $denominator;
    }

    /**
     * The f08 seed-direct leg: best max(Jaccard, Containment) over the
     * retained ngram bags of the two files' (largest, capped) blocks.
     * Deterministic side selection keeps two runs of the same corpus
     * byte-identical.
     *
     * @param list<Block> $as
     * @param list<Block> $bs
     */
    private function directBest(array $as, array $bs): float
    {
        $best = 0.0;
        $left = $this->largest($as);
        $right = $this->largest($bs);
        foreach ($left as $a) {
            if ($a->ngramBag === null) {
                continue; // bag-less block (producer variation): never a witness
            }
            foreach ($right as $b) {
                if ($b->ngramBag === null) {
                    continue;
                }
                $sim = max(
                    $this->jaccard->similarity($a->ngramBag, $b->ngramBag),
                    $this->containment->similarity($a->ngramBag, $b->ngramBag),
                );
                if ($sim > $best) {
                    $best = $sim;
                }
            }
        }
        return $best;
    }

    /**
     * Largest blocks first (size desc, range start/end/id asc), capped at
     * COMPARE_SIDE_CAP — the deterministic bounded side of a direct compare.
     *
     * @param list<Block> $list
     * @return list<Block>
     */
    private function largest(array $list): array
    {
        if (count($list) <= self::COMPARE_SIDE_CAP) {
            return $list;
        }
        usort($list, static function (Block $x, Block $y): int {
            return [-$x->size, $x->range->start, $x->range->end, $x->id]
                <=> [-$y->size, $y->range->start, $y->range->end, $y->id];
        });
        return array_slice($list, 0, self::COMPARE_SIDE_CAP);
    }

    /**
     * One file's token facts from a single throwaway parse. A file that
     * cannot be read or parsed yields honest zeros — parse accounting
     * already reports the failure; the rollup must not crash the report.
     *
     * Declaration labels use the bare `name` property only: AstParser
     * runs no NameResolver, so the php-parser `namespacedName` magic
     * property is uninitialised on these trees and must not be touched.
     *
     * @return array<string, mixed> keys: the SUM_KEYS scalars,
     *         declaration_names (int), call_names (list<string>),
     *         tables (list<string>)
     */
    private function fileFacts(string $path): array
    {
        $facts = array_fill_keys(self::SUM_KEYS, 0);
        $facts['declaration_names'] = 0;
        $facts['call_names'] = [];
        $facts['tables'] = [];
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return $facts;
        }
        $facts['file_lines'] = $this->countLines($contents);
        $stmts = $this->parser->parseFile($path);
        if ($stmts === null) {
            return $facts;
        }

        $decl = [];
        $calls = [];
        $tables = [];
        foreach ($this->finder->find($stmts, static fn(Node $n): bool => true) as $n) {
            if ($n instanceof Node\Expr) {
                // Expression membership is orthogonal to the species
                // chain below: a call site IS an expression (counted in
                // both keys by design); a string scalar is a Scalar, not
                // an Expr, and feeds only the SQL tally.
                $facts['expressions']++;
            }
            if ($n instanceof Node\Stmt\ClassMethod) {
                $facts['methods']++;
                $decl[] = 'm:' . $n->name->toString();
            } elseif ($n instanceof Node\Stmt\Function_) {
                $facts['functions']++;
                $decl[] = 'f:' . $n->name->toString();
            } elseif ($n instanceof Node\Stmt\Class_) {
                if ($n->isAnonymous()) {
                    continue; // no name, no declaration identity (BlockExtractor CLASS_KIND law)
                }
                $facts['classes']++;
                $decl[] = 'c:' . ($n->name?->toString() ?? '');
            } elseif ($n instanceof Node\Stmt\Interface_) {
                $facts['interfaces']++;
                $decl[] = 'i:' . ($n->name?->toString() ?? '');
            } elseif ($n instanceof Node\Stmt\Trait_) {
                $facts['traits']++;
                $decl[] = 't:' . ($n->name?->toString() ?? '');
            } elseif ($n instanceof Node\Stmt\Enum_) {
                $facts['enums']++;
                $decl[] = 'e:' . ($n->name?->toString() ?? '');
            } elseif ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\FuncCall) {
                // The CallGraph species set (the codebase precedent named
                // in the class docblock); dynamic targets count as call
                // sites but carry no distinct label.
                $facts['call_sites']++;
                $label = $this->callLabel($n);
                if ($label !== null) {
                    $calls[$label] = true;
                }
            } elseif ($n instanceof Node\Scalar\String_) {
                $this->tallySql((string) $n->value, $facts, $tables);
            } elseif ($n instanceof Node\Scalar\InterpolatedString) {
                $this->tallySql($this->interpolated($n), $facts, $tables);
            }
        }
        $facts['declaration_names'] = count(array_unique($decl));
        $facts['call_names'] = array_keys($calls);
        $facts['tables'] = array_keys($tables);
        return $facts;
    }

    /** Recognised-SQL string literal -> SqlTableExtractor verb/table tally.
     *
     * @param array<string, mixed> $facts
     * @param array<string, true> $tables
     */
    private function tallySql(string $sql, array &$facts, array &$tables): void
    {
        $extracted = SqlTableExtractor::extract($sql);
        if ($extracted === null) {
            return;
        }
        $facts['sql_refs']++;
        if ($extracted[1] === null) {
            $facts['sql_tableless']++;
            return;
        }
        $tables[$extracted[1]] = true;
    }

    /**
     * Double-quoted/interpolated strings: literal parts joined, expression
     * parts collapsed to a space so a variable at the table position
     * degrades to a null table instead of inventing one.
     */
    private function interpolated(Node\Scalar\InterpolatedString $n): string
    {
        $out = '';
        foreach ($n->parts as $part) {
            if (is_string($part)) {
                $out .= $part;
            } elseif ($part instanceof Node\InterpolatedStringPart) {
                $out .= $part->value;
            } else {
                $out .= ' ';
            }
        }
        return $out;
    }

    /** @return string|null the CallGraph-style qualified label, null when dynamic */
    private function callLabel(Node $call): ?string
    {
        if ($call instanceof Node\Expr\MethodCall && $call->name instanceof Node\Identifier) {
            return $call->name->name;
        }
        if ($call instanceof Node\Expr\StaticCall && $call->name instanceof Node\Identifier) {
            $cls = $call->class instanceof Node\Name ? $call->class->toString() : '?';
            return $cls . '::' . $call->name->name;
        }
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            return $call->name->toString();
        }
        return null;
    }

    /** @return array{root:string,path:string} machine-free display identity */
    private function display(string $key): array
    {
        $folded = PathCanonical::fold($key);
        $best = 0;
        $root = '';
        foreach ($this->relRoots as $r) {
            $prefix = PathCanonical::fold(rtrim($r, '/\\')) . '/';
            $len = strlen($prefix);
            if ($len > $best && str_starts_with($folded, $prefix)) {
                $best = $len;
                $root = $r;
            }
        }
        return [
            'root' => $root === '' ? '' : basename(PathCanonical::separators($root)),
            'path' => PathCanonical::underRoots($key, $this->relRoots),
        ];
    }

    /**
     * Directory label for the mirrored-directory roll-up: '<rootBasename>:<dir>'
     * with the root-stripped relative path, '.' (a root-level file) folding
     * to the empty dir so labels stay tidy.
     */
    private static function dirLabel(string $root, string $path): string
    {
        $dir = dirname($path);
        return $root . ':' . ($dir === '.' ? '' : $dir);
    }

    /**
     * Seed groups (f08 §2): 'path' groups by the relative path after the
     * root strip (mirrored trees), 'name' groups by basename (renamed
     * copies). Members are always distinct disk files — a file never
     * seeds with itself.
     *
     * @param array<string, array{root:string,path:string}> $display
     * @return list<list<string>> groups of >= 2 file keys
     */
    private function seedGroups(array $display, string $how): array
    {
        $groups = [];
        foreach ($display as $key => $d) {
            $token = $how === 'path' ? $d['path'] : basename($d['path']);
            $groups[$token][] = $key;
        }
        $out = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            sort($members);
            $out[] = $members;
        }
        return $out;
    }

    /**
     * @param list<string> $group
     * @return list<array{0:string,1:string}>
     */
    private function pairsOf(array $group): array
    {
        $pairs = [];
        $n = count($group);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $pairs[] = [$group[$i], $group[$j]];
            }
        }
        return $pairs;
    }

    /**
     * One bounded top_files row. The id is content-derived (f08 §2: never
     * positional — a line shift or an unrelated file added to the corpus
     * must not churn it): the scan-relative path joined with the sorted
     * structural hashes of the file's blocks. The root label is display
     * metadata, NOT identity — two roots holding the same relative file
     * ARE the same file's rollup identity (that is what a mirror means).
     * Structural hashes are line-free, which is exactly what keeps the id
     * stable across a re-indent.
     *
     * @param array{root:string,path:string} $d
     * @param list<Block> $fileBlocks
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function fileRow(array $d, array $fileBlocks, int $comparableLines, array $f): array
    {
        $hashes = [];
        foreach ($fileBlocks as $b) {
            $hashes[] = $b->structuralHash;
        }
        sort($hashes);
        return [
            'id' => hash('xxh64', $d['path'] . '|' . implode(',', $hashes)),
            'root' => $d['root'],
            'path' => $d['path'],
            'blocks' => count($fileBlocks),
            'comparable_lines' => $comparableLines,
            'file_lines' => $f['file_lines'],
            'declarations' => array_intersect_key($f, array_flip(self::DECLARATION_SPECIES)),
            'distinct_declarations' => $f['declaration_names'],
            'expressions' => $f['expressions'],
            'call_sites' => $f['call_sites'],
            'distinct_calls' => count($f['call_names']),
            'sql_refs' => $f['sql_refs'],
            'distinct_sql_tables' => count($f['tables']),
        ];
    }

    /** Newline-law line count (trailing newline is not a fresh line). */
    private function countLines(string $contents): int
    {
        if ($contents === '') {
            return 0;
        }
        $n = substr_count($contents, "\n");
        return str_ends_with($contents, "\n") ? $n : $n + 1;
    }
}
