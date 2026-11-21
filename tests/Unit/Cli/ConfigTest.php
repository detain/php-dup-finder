<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

/**
 * Pins the last-resort range guards of Config::__construct (output-056):
 * every [0,1] threshold field must reject out-of-range values AND the
 * non-finite trio (NAN, INF, -INF) that a bare `< / >` comparison lets
 * through — every NaN comparison is false, so an unguarded NaN silently
 * disables its threshold downstream.
 */
final class ConfigTest extends TestCase
{
    /**
     * Constructor param => config key named in the guard message.
     */
    private const FLOAT_FIELDS = [
        'similarityThreshold'            => 'similarity_threshold',
        'treeThreshold'                  => 'tree_threshold',
        'optionalBlocksContainment'      => 'optional_blocks_containment',
        'optionalBlocksMinOverlap'       => 'optional_blocks_min_overlap',
        'irThreshold'                    => 'ir_threshold',
        'mlPairThreshold'                => 'ml_pair_threshold',
        'maxHoleCoverage'                => 'max_hole_coverage',
    ];

    /**
     * @param array<string, float> $extra
     */
    private static function configWith(array $extra): Config
    {
        return new Config(...['paths' => ['src'], 'exclude' => []] + $extra);
    }

    public function testNonFiniteThresholdsAreRejectedWithDescriptiveErrors(): void
    {
        foreach (self::FLOAT_FIELDS as $param => $key) {
            foreach ([NAN, INF, -INF] as $poison) {
                try {
                    self::configWith([$param => $poison]);
                    $this->fail("$key accepted non-finite value " . var_export($poison, true));
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString($key, $e->getMessage());
                    $this->assertStringContainsString('finite', $e->getMessage());
                }
            }
        }
    }

    public function testOutOfRangeFiniteThresholdsAreRejected(): void
    {
        foreach (self::FLOAT_FIELDS as $param => $key) {
            foreach ([-0.1, 1.5] as $poison) {
                try {
                    self::configWith([$param => $poison]);
                    $this->fail("$key accepted out-of-range value " . var_export($poison, true));
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString($key, $e->getMessage());
                }
            }
        }
    }

    public function testBoundaryThresholdsAreAccepted(): void
    {
        foreach (self::FLOAT_FIELDS as $param => $key) {
            foreach ([0.0, 1.0] as $ok) {
                $config = self::configWith([$param => $ok]);
                $this->assertSame($ok, $config->{$param}, "$key must accept boundary $ok");
            }
        }
    }

    public function testDefaultsConstructCleanly(): void
    {
        $config = Config::defaults(['src']);
        $this->assertSame(0.80, $config->similarityThreshold);
        $this->assertSame(1.0, $config->maxHoleCoverage);
    }

    /**
     * The CLI/config-file choke point must surface the armed backstop:
     * a native NAN float rides coerceNumerics() untouched (assertDomain's
     * `< / >` shape cannot see NaN), so Config's constructor is what
     * rejects it — Command's catch turns that into exit 2.
     */
    public function testLoaderSurfacesNonFiniteOverrideRejection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('similarity_threshold');
        (new ConfigLoader())->load(
            paths: ['src'],
            configFile: null,
            overrides: ['similarity_threshold' => NAN],
            profileExclude: null,
        );
    }

    public function testLoaderRejectsNonNumericStringBeforeTheBackstop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('similarity_threshold must be a number');
        (new ConfigLoader())->load(
            paths: ['src'],
            configFile: null,
            overrides: ['similarity_threshold' => 'NAN'],
            profileExclude: null,
        );
    }
}
