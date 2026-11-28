<?php

declare(strict_types=1);

namespace Phpdup\Census;

use Phpdup\Extraction\Block;
use Phpdup\Index\SequenceIndex;

/**
 * Item 3.2 — helper re-implementation detection + adoption counts.
 *
 * The doc-letter mechanism: take each SMALL named function/method (at
 * most {@see self::HELPER_MAX_STATEMENTS} top-level statements — the
 * "single return <expr> or a handful" reading), treat every maximal
 * mined-idiom pattern occurring inside its body as a wildcard-keyed
 * lookup into the 3.1 census, and account every other occurrence of
 * the same pattern as an inline re-implementation of that helper.
 *
 * The wildcard is the ABSTRACT fold key: SubtreeFold already renumbers
 * subtree-local variables (parameter slots become the same __V order)
 * and blanks scalars, so one census abstract-group covers every
 * literal-drifted spelling of the helper's idiom. Grouping at the
 * abstract tier is the letter's "parameters become wildcards, look up
 * the idiom indexes" reading; within a group the exact spellings are
 * summed, and the lexicographically-smallest exact hashes rides the
 * row as the representative (the group's fold shape pins nodes ==
 * equal by construction, so the row's nodes/rootType are the group's).
 *
 * §5c law respected: ZERO new walkers. This class only RE-QUERIES what
 * SubtreeMiner already folded into SubtreeCensus and SequenceIndex
 * already holds per file; a second traversal of ASTs would be the exact
 * anti-pattern the arbitration forbids. It runs master-side after
 * blocks exist, over the armed census — nothing crosses the worker wire
 * (SOCKETPAIR law untouched: no new flavor, no payload change).
 *
 * Sampling caveat (inherited, disclosed): helper-body membership is
 * proven via the census's per-file wire sample lines, which the miner
 * seals at ROW_CAP (4) per (file, pattern) site row. A helper body
 * reusing one idiom more than the cap allows could under-state
 * `defSites` (and inflate same-file copies). Real helpers are small and
 * repeat an idiom at most a couple of times; the acceptance corpus
 * (Env::isDev/baseUrl, one occurrence each) is far inside the bound.
 *
 * v1 twin semantics: when several small helpers share one group (e.g.
 * both `Env::isDev` and each inline-copy host body), EACH helper gets
 * its own rows and the others count as its copies — honest per-helper
 * accounting; group-level dedupe is `patternsFound()`.
 *
 * Deterministic: rows sorted by (defFile, defStart, patternAbstract,
 * representative exact) and every input map is order-normalised, so
 * permuted ingest renders byte-identical tables.
 */
final class HelperAdoption
{
    /** Doc letter: a helper is a SMALL body — this caps its top-level statement count. */
    public const HELPER_MAX_STATEMENTS = 5;

    /** Canonical copy-site 'file:line' samples carried per row. */
    public const COPY_SAMPLE_CAP = 10;

    /** kinds admitted as helper definitions (named functions/methods). */
    private const HELPER_KINDS = ['function', 'method'];

    /** @param list<HelperCopy> $rows */
    private function __construct(
        private readonly array $rows,
        private readonly int $helpers,
        private readonly int $patterns,
    ) {
    }

    /**
     * Pure derivation over the armed shared substrate. Requires the 3.1
     * census (helpers ride the idiom flavor — a wiring bug otherwise,
     * Fail Fast) and the Block list from the pipeline state.
     *
     * @param list<Block> $blocks
     */
    public static function analyze(SequenceIndex $index, array $blocks): self
    {
        $census = $index->idiomCensus();
        if ($census === null) {
            throw new \LogicException('helper adoption requires the armed idiom census — armIdioms() before armHelpers()');
        }

        /** @var array<string,list<IdiomRow>> abstract => rows (exact-ascending) */
        $groups = [];
        foreach ($census->maximalRows() as $row) {
            $groups[$row->abstractHash][] = $row;
        }
        foreach ($groups as &$members) {
            usort($members, static fn(IdiomRow $a, IdiomRow $b): int => $a->exactHash <=> $b->exactHash);
        }
        unset($members);

        $rows = [];
        foreach ($blocks as $block) {
            if (!in_array($block->kind, self::HELPER_KINDS, true) || $block->name === null || $block->name === '') {
                continue;
            }
            $stmts = self::bodyStatements($index, $block);
            if ($stmts === null || $stmts < 1 || $stmts > self::HELPER_MAX_STATEMENTS) {
                continue;
            }
            foreach ($groups as $members) {
                $copy = self::groupFor($block, $stmts, $members, $census);
                if ($copy !== null) {
                    $rows[] = $copy;
                }
            }
        }

        usort($rows, static fn(HelperCopy $a, HelperCopy $b): int =>
            [$a->defFile, $a->defStart, $a->patternAbstract, $a->patternExact]
                <=> [$b->defFile, $b->defStart, $b->patternAbstract, $b->patternExact]);

        $helpers  = [];
        $patterns = [];
        foreach ($rows as $r) {
            $helpers[$r->defFile . ':' . $r->defStart] = true;
            $patterns[$r->patternAbstract]             = true;
        }

        return new self(rows: $rows, helpers: count($helpers), patterns: count($patterns));
    }

    /** @return list<HelperCopy> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** Distinct helper bodies that carry at least one adopted idiom. */
    public function helperCount(): int
    {
        return $this->helpers;
    }

    /**
     * Distinct maximal idiom groups found re-implemented (the honest
     * ledger breadth: twin helpers share ONE pattern here, where rows()
     * accounts the pattern once per def-helper).
     */
    public function patternsFound(): int
    {
        return $this->patterns;
    }

    /**
     * Total inline re-implementation sites across all rows. NOTE: with
     * cross-file twin helpers the same site is counted once per helper
     * that treats it as a copy (disclosed v1 posture); patternsFound()
     * is the deduped breadth view.
     */
    public function inlineSitesTotal(): int
    {
        $total = 0;
        foreach ($this->rows as $r) {
            $total += $r->inlineSites;
        }
        return $total;
    }

    /**
     * Top-level statement count of the helper body: the smallest list
     * the worker recorded for this file whose ownerKind matches the
     * block kind and whose span lies inside the block range. Null when
     * no such list exists (empty body, or a kind the walker did not
     * label for this block).
     */
    private static function bodyStatements(SequenceIndex $index, Block $block): ?int
    {
        $best = null;
        foreach ($index->listsFor($block->file) as $entry) {
            if ($entry['ownerKind'] !== $block->kind) {
                continue;
            }
            if ($entry['firstLine'] < $block->range->start || $entry['lastLine'] > $block->range->end) {
                continue;
            }
            if ($best === null || ($entry['lastLine'] - $entry['firstLine']) < ($best['lastLine'] - $best['firstLine'])) {
                $best = $entry;
            }
        }
        return $best === null ? null : count($best['hashes']);
    }

    /**
     * One (helper, idiom-group) row over the group's summed counters, or
     * null when the group does not occur inside this helper's body or
     * has no copies left over.
     *
     * @param list<IdiomRow> $members exact-ascending (representative first)
     */
    private static function groupFor(Block $block, int $stmts, array $members, SubtreeCensus $census): ?HelperCopy
    {
        $file      = $block->file;
        $sites     = 0;
        $def       = 0;
        $perFile   = [];
        $locations = [];
        foreach ($members as $row) {
            $sites += $row->sites;
            foreach ($census->sitesPerFile($row->exactHash) as $f => $count) {
                $perFile[$f] = ($perFile[$f] ?? 0) + $count;
            }
            foreach ($census->linesPerFile($row->exactHash)[$file] ?? [] as $line) {
                if ($line >= $block->range->start && $line <= $block->range->end) {
                    $def++;
                }
            }
            foreach ($row->locations as $loc) {
                $line     = self::lineOf($loc);
                $ownFile  = self::fileOf($loc);
                if ($ownFile === $file && $line !== null && $line >= $block->range->start && $line <= $block->range->end) {
                    continue; // the helper's own occurrence is not a copy of it
                }
                $locations[] = $loc;
            }
        }
        if ($def === 0 || $sites <= $def) {
            return null;
        }

        $copies = [];
        foreach ($perFile as $copyFile => $count) {
            $remaining = $copyFile === $file ? $count - $def : $count;
            if ($remaining > 0) {
                $copies[$copyFile] = $remaining;
            }
        }
        ksort($copies);
        sort($locations);

        $first = $members[0];
        return new HelperCopy(
            helperLabel:     $block->qualifiedName(),
            defFile:         $file,
            defStart:        $block->range->start,
            defEnd:          $block->range->end,
            helperKind:      $block->kind,
            defStatements:   $stmts,
            patternExact:    $first->exactHash,
            patternAbstract: $first->abstractHash,
            nodes:           $first->nodes,
            rootType:        $first->rootType,
            sitesTotal:      $sites,
            defSites:        $def,
            inlineSites:     $sites - $def,
            inlineFiles:     $copies,
            copyLocations:   array_slice($locations, 0, self::COPY_SAMPLE_CAP),
        );
    }

    private static function fileOf(string $location): string
    {
        $pos = strrpos($location, ':');
        return $pos === false ? $location : substr($location, 0, $pos);
    }

    private static function lineOf(string $location): ?int
    {
        $pos = strrpos($location, ':');
        return $pos === false ? null : (int) substr($location, $pos + 1);
    }
}
