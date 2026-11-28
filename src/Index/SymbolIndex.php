<?php

declare(strict_types=1);

namespace Phpdup\Index;

use Phpdup\Extraction\Block;

/**
 * Name-level duplicate-symbol index (item 3.4, improve_logic.md:260-261).
 *
 * The bag-Jaccard pipeline compares payload first: two functions that share
 * a name but diverged in body score low and never meet, so the very case the
 * doc names — "same filename different logic", shadowed canonical helpers —
 * is invisible. This index keys blocks by their *symbol identity* instead of
 * their content:
 *
 *  - name pairs:      same (case-folded) function/method name, different owner
 *                     (file + class). Cross-file same-name IS the shadow
 *                     family; same-owner groups are ordinary overloads and
 *                     are skipped.
 *  - class pairs:     same class SHORT name, different qualified class, same
 *                     method name — the one exception to the magic-name
 *                     stop-list, catching `{A,B}\RateLimitException` twin
 *                     constructors that diverged.
 *  - basename pairs:  named blocks from files sharing a basename, different
 *                     path — the doc's "same filename, different logic"
 *                     archetype (S1-46): the cross-comparison catches a
 *                     renamed helper whose files still share a basename,
 *                     which the name route alone would miss. Capped by the
 *                     group-block ceiling below.
 *
 * Every key is lowercased and sorted, groups are capped (sites AND total
 * blocks), over-cap groups are skipped entirely (a 55-site `toArray` family
 * is noise, not evidence; a 60-method twin file would flood), and pair keys
 * are canonicalised lo<hi so the output is insertion-order independent —
 * the same law the F3 mass filter pins (§2.4 determinism).
 *
 * Construction is master-side only: it reads the BlockIndex AFTER workers
 * return (the same residency point the idiom/mining passes use), so no new
 * field ever crosses the worker wire (SOCKETPAIR law, §4 blocker 4).
 */
final class SymbolIndex
{
    /** Pair-reason tokens — the vocabulary the injection and evidence layers share. */
    public const REASON_NAME = 'name';
    public const REASON_CLASS = 'class';
    public const REASON_BASENAME = 'basename';

    /**
     * Magic names are language protocols, not duplication evidence. `__*`
     * names never pair through the name/basename routes; the class route is
     * the deliberate exception (twin constructors of twin classes are the
     * signal, not the magic name).
     */
    private const MAGIC_PREFIX = '__';

    /** Distinct owners (name groups) / classes (class groups) / files (basename groups) a group may keep before it is noise. */
    private const MAX_GROUP_SITES = 12;

    /**
     * Total blocks a group may pool before its cross-product stops being
     * evidence and becomes a quadratic flood (a twin 60-method file pair
     * would otherwise mint ~3,600 forced candidates from one key). Over-cap
     * groups are dropped deterministically, same law as the site cap.
     */
    private const MAX_GROUP_BLOCKS = 32;

    /** @var list<array{0:string, 1:string, 2:string}> forced pairs, canonical + sorted */
    private array $pairs = [];

    /** @var array<string, Block> every symbol-bearing block, id => block (references) */
    private array $named = [];

    /** @var array<string, true> canonical pair key => seen; insertion order = source priority (name > class > basename) */
    private array $emitted = [];

    /**
     * One pass over the final BlockIndex. Call AFTER cohort filtering /
     * fragment removal (ClusterStage does), never on worker-local state.
     */
    public function build(BlockIndex $index): void
    {
        $this->pairs = [];
        $this->emitted = [];
        $this->named = [];

        $nameGroups = [];
        $classGroups = [];
        $baseGroups = [];

        foreach ($index->all() as $block) {
            $name = $block->name;
            if ($name === null || $name === '') {
                continue; // fragments and control blocks carry no symbol identity
            }
            $this->named[$block->id] = $block;
            $lower = strtolower($name);

            // --- class route first-pass: magic names may pair here only.
            if ($block->class !== null) {
                $short = self::shortName($block->class);
                if ($short !== '') {
                    $classGroups[strtolower($short) . '|' . $lower][$block->class][$block->id] = $block;
                }
            }

            if (str_starts_with($lower, self::MAGIC_PREFIX)) {
                continue; // magic methods pair through the class route only
            }

            // --- name route: same lowercase name, different owner (file + class).
            $owner = $block->file . '|' . (string) $block->class;
            $nameGroups[$lower][$owner][$block->id] = $block;

            // --- basename route: same file basename, different path, any
            // named block (the doc pairs same-basename FILES — renamed
            // helpers must still meet; the block ceiling bounds the product).
            $base = strtolower((string) basename($block->file));
            if ($base !== '') {
                $baseGroups[$base][$block->file][$block->id] = $block;
            }
        }

        ksort($nameGroups);
        foreach ($nameGroups as $key => $sites) {
            $this->collect(self::REASON_NAME, (string) $key, $sites);
        }
        ksort($classGroups);
        foreach ($classGroups as $key => $sites) {
            $this->collect(self::REASON_CLASS, (string) $key, $sites);
        }
        ksort($baseGroups);
        foreach ($baseGroups as $key => $sites) {
            $this->collect(self::REASON_BASENAME, (string) $key, $sites);
        }

        usort($this->pairs, static function (array $a, array $b): int {
            return [$a[2], $a[0], $a[1]] <=> [$b[2], $b[0], $b[1]];
        });
    }

    /**
     * Forced pairs as [loId, hiId, reason] triples, canonical (lo<hi strcmp),
     * deduped, sorted by (reason, lo, hi) — deterministic for any insertion order.
     *
     * @return list<array{0:string, 1:string, 2:string}>
     */
    public function forcedPairs(): array
    {
        return $this->pairs;
    }

    public function countForcedPairs(): int
    {
        return count($this->pairs);
    }

    public function armed(): bool
    {
        return $this->pairs !== [];
    }

    /**
     * Every block that carries a symbol identity, id => block. The evidence
     * analyzer resolves forced-pair endpoints through this map — reference
     * only, the same objects the BlockIndex holds (no copies, no wire).
     *
     * @return array<string, Block>
     */
    public function blocksById(): array
    {
        return $this->named;
    }

    /**
     * Message vocabulary for the report side: route token => human label.
     */
    public static function routeLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_NAME => 'function-name',
            self::REASON_CLASS => 'twin-class',
            self::REASON_BASENAME => 'file-basename',
            default => $reason,
        };
    }

    /**
     * Cap the group (by distinct site keys) and emit pairs ACROSS sites only
     * — same-owner same-name blocks are overloads/duplicates of one slot, not
     * cross-symbol evidence (a class defining `go` twice is not a duplicate
     * `go` family). Over-cap groups are dropped ENTIRELY — deterministic,
     * never arbitrary — the same posture as the mass filter's over-cap drop.
     *
     * @param array<string, array<string, Block>> $sites site-key => (block id => block)
     */
    private function collect(string $reason, string $key, array $sites): void
    {
        ksort($sites);
        if (count($sites) > self::MAX_GROUP_SITES) {
            return;
        }

        $poolSize = 0;
        foreach ($sites as $id => $blocks) {
            ksort($blocks);
            $sites[$id] = $blocks;
            $poolSize += count($blocks);
        }
        if ($poolSize > self::MAX_GROUP_BLOCKS) {
            return; // the cross-product stopped being evidence; drop whole
        }

        $keys = array_keys($sites);
        $n = count($keys);
        for ($x = 0; $x < $n; $x++) {
            for ($y = $x + 1; $y < $n; $y++) {
                foreach ($sites[$keys[$x]] as $a) {
                    foreach ($sites[$keys[$y]] as $b) {
                        $lo = $a->id <= $b->id ? $a->id : $b->id;
                        $hi = $a->id <= $b->id ? $b->id : $a->id;
                        $pairKey = $lo . '|' . $hi;
                        if (isset($this->emitted[$pairKey])) {
                            continue; // an earlier (higher-priority) source owns this pair
                        }
                        $this->emitted[$pairKey] = true;
                        $this->pairs[] = [$lo, $hi, $reason];
                    }
                }
            }
        }
    }

    /**
     * Last namespace segment of a qualified class string.
     */
    private static function shortName(string $qualified): string
    {
        $pos = strrpos($qualified, '\\');
        return $pos === false ? $qualified : substr($qualified, $pos + 1);
    }
}
