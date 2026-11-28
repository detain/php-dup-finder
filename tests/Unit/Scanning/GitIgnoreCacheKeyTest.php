<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;

/**
 * Item 5.2's .gitignore leg (W2a) — the cache-salt mandate: flipping
 * honor_gitignore must move BOTH derived cache keys (the IndexStore key
 * fed by PreprocessStage::indexStoreConfigKey and the ClusterCache key fed
 * by ClusterStage::cacheConfigKey), on the allowedKinds / window_oversized
 * precedent, so a warm snapshot from the other posture can never be
 * replayed. The binding is prophylactic at the block level (the flag
 * prunes INPUT paths, it does not change a surviving file's block
 * content), but the whole-roster law (once-026) keys every behavior
 * knob into both stores regardless — and the one-time key-namespace shift
 * invalidating existing snapshots is the disclosed, accepted cost.
 *
 * Key layer only: the store/replay layers are owned by
 * tests/Unit/Cli/CacheKeyKnobReplayTest.php and the persistence suites;
 * this file pins that the honor_gitignore leg EXISTS in both rosters.
 */
final class GitIgnoreCacheKeyTest extends TestCase
{
    public function testHonorGitignoreFlipSeparatesIndexStoreKey(): void
    {
        $off = new Config(paths: ['/x'], exclude: []);
        $on = new Config(paths: ['/x'], exclude: [], honorGitignore: true);

        $stage = new PreprocessStage(useCache: true);
        $keyOf = new \ReflectionMethod(PreprocessStage::class, 'indexStoreConfigKey');

        $keyOff = $keyOf->invoke($stage, $off);
        $keyOn = $keyOf->invoke($stage, $on);

        self::assertIsString($keyOff);
        self::assertNotSame($keyOff, $keyOn,
            'honor_gitignore changes which files feed the index — the IndexStore key must bind it');
        self::assertSame($keyOff, $keyOf->invoke($stage, $off),
            'non-vacuity: the key must be a pure function of the config');
    }

    public function testHonorGitignoreFlipSeparatesClusterCacheKey(): void
    {
        $off = new Config(paths: ['/x'], exclude: []);
        $on = new Config(paths: ['/x'], exclude: [], honorGitignore: true);

        $stage = new ClusterStage(exactOnly: false);
        $keyOf = new \ReflectionMethod(ClusterStage::class, 'cacheConfigKey');

        $keyOff = $keyOf->invoke($stage, $off);
        $keyOn = $keyOf->invoke($stage, $on);

        self::assertIsString($keyOff);
        self::assertNotSame($keyOff, $keyOn,
            'honor_gitignore changes the corpus a cluster was built from — the cluster key must bind it');
        self::assertSame($keyOff, $keyOf->invoke($stage, $off),
            'non-vacuity: the key must be a pure function of the config');
    }
}
