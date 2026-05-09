<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ConfigLoader;

final class ConfigLoaderTest extends TestCase
{
    public function testValidateAcceptsEmptyObject(): void
    {
        (new ConfigLoader())->validate([]);
        $this->expectNotToPerformAssertions();
    }

    public function testValidateAcceptsFullyPopulatedConfig(): void
    {
        (new ConfigLoader())->validate([
            'paths'                => ['src'],
            'exclude'              => ['vendor/**'],
            'min_block_size'       => 5,
            'max_block_size'       => 800,
            'normalization_mode'   => 'aggressive',
            'similarity_threshold' => 0.85,
            'tree_threshold'       => 0.85,
            'min_cluster_impact'   => 20,
            'max_df'               => 0.01,
            'ngram_size'           => 5,
            'cache_dir'            => '.phpdup-cache',
            'parallelism'          => 'auto',
            'workers'              => 0,
            'incremental'          => true,
            'lazy_ast'             => true,
            'report'               => ['html' => 'out/html', 'json' => 'out/phpdup.json'],
        ]);
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsUnknownTopLevelKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown config key 'foo'");
        (new ConfigLoader())->validate(['foo' => 1]);
    }

    public function testRejectsUnknownReportKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown config key 'report.markdown'");
        (new ConfigLoader())->validate(['report' => ['markdown' => 'out.md']]);
    }

    public function testRejectsNonListPaths(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paths must be a list');
        (new ConfigLoader())->validate(['paths' => ['src' => 'src']]);
    }

    public function testRejectsEmptyPaths(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paths must not be empty');
        (new ConfigLoader())->validate(['paths' => []]);
    }

    public function testRejectsMinAboveMax(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('min_block_size must be <= max_block_size');
        (new ConfigLoader())->validate([
            'min_block_size' => 50,
            'max_block_size' => 10,
        ]);
    }

    public function testRejectsBadNormalizationMode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('normalization_mode must be one of strict|default|aggressive');
        (new ConfigLoader())->validate(['normalization_mode' => 'looser']);
    }

    public function testRejectsThresholdOutOfRange(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('similarity_threshold must be in [0, 1]');
        (new ConfigLoader())->validate(['similarity_threshold' => 1.5]);
    }

    public function testRejectsNgramSizeBelowMinimum(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ngram_size must be >= 2');
        (new ConfigLoader())->validate(['ngram_size' => 1]);
    }

    public function testRejectsNgramSizeAboveMaximum(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ngram_size must be <= 10');
        (new ConfigLoader())->validate(['ngram_size' => 99]);
    }

    public function testRejectsNonBooleanIncremental(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('incremental must be a boolean');
        (new ConfigLoader())->validate(['incremental' => 'yes']);
    }

    public function testIncludesSourceFileNameInError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('in /tmp/phpdup.json');
        (new ConfigLoader())->validate(['workers' => -1], '/tmp/phpdup.json');
    }

    public function testKindsAcceptsValidEntries(): void
    {
        (new ConfigLoader())->validate(['kinds' => ['method', 'closure']]);
        $this->expectNotToPerformAssertions();
    }

    public function testKindsRejectsUnknownKind(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("kinds[] entry 'lambda' must be one of");
        (new ConfigLoader())->validate(['kinds' => ['lambda']]);
    }

    public function testSortAcceptsValidSpecs(): void
    {
        (new ConfigLoader())->validate(['sort' => 'impact:desc']);
        (new ConfigLoader())->validate(['sort' => 'members']);
        (new ConfigLoader())->validate(['sort' => 'block-size:asc']);
        (new ConfigLoader())->validate(['sort' => '+lines']);
        $this->expectNotToPerformAssertions();
    }

    public function testSortRejectsUnknownKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sort: Invalid sort key "complexity"');
        (new ConfigLoader())->validate(['sort' => 'complexity']);
    }

    public function testSortRejectsUnknownDirection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sort: Invalid sort direction');
        (new ConfigLoader())->validate(['sort' => 'impact:sideways']);
    }

    public function testSortRejectsEmptyString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sort must be a non-empty string');
        (new ConfigLoader())->validate(['sort' => '']);
    }

    public function testSortRejectsNonStringValues(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sort must be a non-empty string');
        (new ConfigLoader())->validate(['sort' => ['impact', 'members']]);
    }

    public function testSortOverrideTakesPrecedenceOverConfigFile(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        file_put_contents($tmp, json_encode(['sort' => 'similarity:asc']));
        try {
            $config = (new ConfigLoader())->load(
                paths: ['src'],
                configFile: $tmp,
                overrides: ['sort' => 'lines:desc'],
            );
            $this->assertSame('lines:desc', $config->sort);
        } finally {
            @unlink($tmp);
        }
    }

    public function testKindsOverrideTakesPrecedenceOverConfigFile(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        file_put_contents($tmp, json_encode(['kinds' => ['method']]));
        try {
            $config = (new ConfigLoader())->load(
                paths: ['src'],
                configFile: $tmp,
                overrides: ['allowed_kinds' => ['function', 'closure']],
            );
            $this->assertSame(['function', 'closure'], $config->allowedKinds);
        } finally {
            @unlink($tmp);
        }
    }
}
