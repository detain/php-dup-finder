<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

/**
 * Two-sided drift tripwire (ft-r2 [M-1] remediation, owner-gate R8):
 * every key ConfigLoader::shapeOverrides() ADMITS for per-directory
 * .phpdup.json files must be CONSUMED by Config::withOverrides(), or sit
 * deliberately in the BANKED allowlist below. This is the mechanical
 * guard that would have caught the m1 orphan class (candidate_budget,
 * fixed in e221652) and its six silently-dropped siblings.
 *
 * Nothing here hand-copies key lists: the emitted set is derived by
 * feeding shapeOverrides a fully-populated per-dir payload; the consumed
 * set is derived behaviorally — for each emitted key, a sentinel value
 * that MOVES no property of the derived Config marks the key as an
 * orphan. A new admitted-but-unconsumed key fails the belt unless it is
 * consciously banked, and the planted-key test proves the belt itself is
 * not vacuous.
 *
 * HAND-MAINTAINED DISCLOSURE (p0d-r3 [m-1]): fullPerDirectoryPayload() is
 * the only input to the emitted-side derivation, and it is maintained by
 * hand. If shapeOverrides ever admits a key that the payload does not
 * cover, this file silently under-exercises admission: the assertCount
 * below stays green and the orphan class re-opens. Treat the two counts
 * as the tripwire's own coverage promise — if they are stale, the belt is
 * blind, not clean.
 *
 * BUMP PROTOCOL (p0d-r3 [n2]): extend the payload → run → the key lands
 * in consumed (fix withOverrides), in orphans (fix or BANK it), or the
 * emitted count grows (only then bump assertCount(EMITTED/CONSUMED) —
 * never to silence a failure, only to record genuinely-new admission).
 *
 * SHRINK PROTOCOL (shell-S22): dropping a key from the per-dir admit list
 * means dropping it from the payload too and lowering BOTH counts (the
 * emitted set went 27→23 when the path/ref-bearing family went root-only;
 * consumed 21→17, banked untouched at 6). The shrink is pinned from the
 * other side by testPathRefBearingKeysAreNotAdmittedPerDirectory, so a
 * re-admission cannot sneak back through the payload half alone.
 */
final class PerDirOverrideBeltTest extends TestCase
{
    /**
     * Keys shapeOverrides emits but withOverrides does not consume —
     * every reader is globally root-anchored (sort: ReportStage;
     * optional_blocks_*: ClusterStage/RefactorStage), so per-file
     * consumption would be misleading; the scope decision (admission
     * exclusion vs root-only documentation) is owner-gate R8.
     */
    private const BANKED = [
        // owner-gate R8 (ft-r2 M-1): validated per-dir, globally-scoped reader, admission TBD
        'sort',
        // owner-gate R8 (ft-r2 M-1): validated per-dir, globally-scoped reader, admission TBD
        'optional_blocks_enabled',
        // owner-gate R8 (ft-r2 M-1): validated per-dir, globally-scoped reader, admission TBD
        'optional_blocks_containment',
        // owner-gate R8 (ft-r2 M-1): validated per-dir, globally-scoped reader, admission TBD
        'optional_blocks_min_overlap',
        // owner-gate R8 (ft-r2 M-1): validated per-dir, globally-scoped reader, admission TBD
        'optional_blocks_max_per_cluster',
        // owner-gate R8 (ft-r2 M-1): validated per-dir, globally-scoped reader, admission TBD
        'optional_blocks_min_segment_length',
    ];

    /** @return array<string,mixed> shapeOverrides-input payload covering every admitted shape */
    public static function fullPerDirectoryPayload(): array
    {
        return [
            'min_block_size'       => 17,
            'max_block_size'       => 413,
            'normalization_mode'   => 'strict',
            'similarity_threshold' => 0.44,
            'tree_threshold'       => 0.45,
            'min_cluster_impact'   => 7,
            'max_df'               => 0.33,
            'ngram_size'           => 7,
            'sort'                 => 'members:asc',
            'fail_on_impact'       => 11,
            'max_clusters'         => 12,
            'candidate_budget'     => 777,
            'kinds'                => ['method'],
            // item 5.2: both exclude keys are admitted and CONSUMED
            // (append-merge + reset opt-out in Config::mergeExclude()).
            'exclude'              => ['belt/exclude/**'],
            'exclude_reset'        => true,
            'optional_blocks'      => [
                'enabled'            => false,
                'containment'        => 0.12,
                'min_overlap'        => 0.13,
                'max_per_cluster'    => 9,
                'min_segment_length' => 4,
            ],
            'db_symbols' => [
                'methods'   => ['BeltEntity::probe' => 'db.read'],
                'functions' => ['belt_fn' => 'db.write'],
            ],
            'low_memory' => true,
        ];
    }

    public function testEveryEmittedPerDirKeyIsConsumedOrBanked(): void
    {
        $emitted = self::emittedKeys();
        self::assertCount(23, $emitted, 'fully-populated payload must exercise every admitted shape');

        [$consumed, $orphans] = self::classifyKeys($emitted);

        sort($orphans);
        $banked = self::BANKED;
        sort($banked);
        self::assertSame(
            $banked,
            $orphans,
            'shapeOverrides admits keys that withOverrides neither consumes nor has banked (owner-gate R8): '
            . implode(', ', array_diff($orphans, $banked))
            . ' — a newly admitted key must be consumed in Config::withOverrides() or consciously BANKED there.',
        );
        self::assertCount(17, $consumed, 'the consumed half of the belt is 23 emitted minus 6 banked');
    }

    /**
     * shell-S22 (php-dup-finder-shell-026): the emitted-set pin has a
     * negative half. The path/ref-bearing family — the keys that choose a
     * file to WRITE or an external ref to shell out to — must never be
     * admitted per-directory, not even as a silent drop: a payload made of
     * nothing else must shape to NOTHING. Re-admitting any of the four (or
     * inventing a fifth write-bearing key and shipping it through
     * shapeOverrides) fails here before it can ever reach a derived Config.
     */
    public function testPathRefBearingKeysAreNotAdmittedPerDirectory(): void
    {
        $loader = new ConfigLoader();
        $method = new \ReflectionMethod($loader, 'shapeOverrides');

        foreach (['baseline', 'baseline_out', 'diff_base', 'debug_log'] as $rootOnlyKey) {
            /** @var array<string,mixed> $emitted */
            $emitted = $method->invoke($loader, [$rootOnlyKey => '/belt/planted-' . $rootOnlyKey]);
            self::assertSame(
                [],
                $emitted,
                "shapeOverrides must not admit the root-only key '$rootOnlyKey' per-directory (shell-S22)",
            );
        }
    }

    /**
     * Non-vacuity proof: a planted key that shapeOverrides-like admission
     * emits but withOverrides ignores MUST classify as an unconsumed,
     * unbanked orphan — i.e. the belt mechanism actually bites.
     */
    public function testPlantedOrphanIsCaughtByTheSameMechanism(): void
    {
        $emitted   = self::emittedKeys();
        $emitted[] = 'planted_orphan';
        // classifyKeys exercises the planted key exactly like a real
        // emitted key via its $extraSentinel fallback.
        [, $orphans] = self::classifyKeys($emitted, extraSentinel: 'not-a-real-key');
        self::assertContains('planted_orphan', $orphans, 'the belt must fail loud on unbanked orphans');
        self::assertContains('sort', $orphans);
    }

    /**
     * Derive the EMITTED key set behaviorally from the private admission
     * table itself (no hand copy): feed the full payload, take result keys.
     *
     * @return array<string,mixed> emitted key => sentinel value
     */
    private static function emittedSentinels(): array
    {
        $loader = new ConfigLoader();
        $method = new \ReflectionMethod($loader, 'shapeOverrides');
        /** @var array<string,mixed> $emitted */
        $emitted = $method->invoke($loader, self::fullPerDirectoryPayload());
        self::assertIsArray($emitted);
        return $emitted;
    }

    /** @return list<string> */
    private static function emittedKeys(): array
    {
        return array_keys(self::emittedSentinels());
    }

    /**
     * For each emitted key: build withOverrides([key => sentinel]) off a
     * common root and diff the resulting public readonly property vectors
     * against withOverrides([]). Any movement means the key is consumed.
     *
     * @param list<string> $keys
     * @return array{0: array<string,list<string>>, 1: list<string>} [consumedKey=>movedProps, orphans]
     */
    private static function classifyKeys(array $keys, mixed $extraSentinel = null): array
    {
        $sentinels = self::emittedSentinels();
        if ($extraSentinel !== null) {
            $sentinels['planted_orphan'] = $extraSentinel;
        }

        // Non-empty baseline exclude so BOTH item-5.2 keys move a property
        // against it: `exclude` appends, `exclude_reset` clears to [].
        $root     = new Config(paths: [__DIR__], exclude: ['belt-root/**']);
        $baseline = get_object_vars($root->withOverrides([]));

        $consumed = [];
        $orphans  = [];
        foreach ($keys as $key) {
            $derived = get_object_vars($root->withOverrides([$key => $sentinels[$key] ?? $extraSentinel]));
            $moved   = [];
            foreach ($baseline as $prop => $value) {
                if (($derived[$prop] ?? null) !== $value) {
                    $moved[] = $prop;
                }
            }
            if ($moved === []) {
                $orphans[] = $key;
            } else {
                $consumed[$key] = $moved;
            }
        }
        return [$consumed, $orphans];
    }
}
