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
}
