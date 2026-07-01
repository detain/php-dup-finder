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

    public function testDbAwareDefaultsOff(): void
    {
        $config = (new ConfigLoader())->load(paths: ['src'], configFile: null);
        $this->assertFalse($config->dbAware);
    }

    public function testDbAwareReadsFromConfigFile(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        file_put_contents($tmp, json_encode(['db_aware' => true]));
        try {
            $config = (new ConfigLoader())->load(paths: ['src'], configFile: $tmp);
            $this->assertTrue($config->dbAware);
        } finally {
            @unlink($tmp);
        }
    }

    public function testDbAwareOverrideBeatsConfigFile(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        file_put_contents($tmp, json_encode(['db_aware' => false]));
        try {
            $config = (new ConfigLoader())->load(
                paths: ['src'],
                configFile: $tmp,
                overrides: ['db_aware' => true],
            );
            $this->assertTrue($config->dbAware);
        } finally {
            @unlink($tmp);
        }
    }

    public function testDbAwareValidationRejectsNonBoolean(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db_aware must be a boolean');
        (new ConfigLoader())->validate(['db_aware' => 'yes']);
    }

    public function testTrinityCollapseDefaultsOff(): void
    {
        $config = (new ConfigLoader())->load(paths: ['src'], configFile: null);
        $this->assertFalse($config->trinityCollapse);
    }

    public function testTrinityCollapseReadsFromConfigFile(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        file_put_contents($tmp, json_encode(['trinity_collapse' => true]));
        try {
            $config = (new ConfigLoader())->load(paths: ['src'], configFile: $tmp);
            $this->assertTrue($config->trinityCollapse);
        } finally {
            @unlink($tmp);
        }
    }

    public function testTrinityCollapseValidationRejectsNonBoolean(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('trinity_collapse must be a boolean');
        (new ConfigLoader())->validate(['trinity_collapse' => 1]);
    }

    public function testValidateAcceptsLowMemory(): void
    {
        (new ConfigLoader())->validate(['low_memory' => true]);
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsLowMemoryNotBool(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['low_memory' => 'yes']);
    }

    public function testScorerDefaultsToDefault(): void
    {
        $config = (new ConfigLoader())->load(paths: ['src'], configFile: null);
        $this->assertSame('default', $config->scorer);
        $this->assertSame(0.85, $config->irThreshold);
    }

    public function testScorerOverrideSetsIrMode(): void
    {
        $config = (new ConfigLoader())->load(
            paths: ['src'],
            configFile: null,
            overrides: ['scorer' => 'ir', 'ir_threshold' => 0.9],
        );
        $this->assertSame('ir', $config->scorer);
        $this->assertSame(0.9, $config->irThreshold);
    }

    public function testScorerValidationRejectsUnknownTier(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scorer must be one of default|ir');
        (new ConfigLoader())->validate(['scorer' => 'magic']);
    }

    public function testIrThresholdValidationRejectsOutOfRange(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ir_threshold must be in [0, 1]');
        (new ConfigLoader())->validate(['ir_threshold' => 2.0]);
    }

    public function testMlPairUrlDefaultsToEmpty(): void
    {
        $config = (new ConfigLoader())->load(paths: ['src'], configFile: null);
        $this->assertSame('', $config->mlPairUrl);
        $this->assertSame(0.80, $config->mlPairThreshold);
    }

    public function testMlPairUrlAcceptsHttp(): void
    {
        $config = (new ConfigLoader())->load(
            paths: ['src'],
            configFile: null,
            overrides: ['ml_pair_url' => 'https://ml.example.com/api'],
        );
        $this->assertSame('https://ml.example.com/api', $config->mlPairUrl);
    }

    public function testMlPairUrlValidationRejectsFileScheme(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ml_pair_url');
        (new ConfigLoader())->validate(['ml_pair_url' => 'file:///etc/passwd']);
    }

    public function testMlPairThresholdValidationRejectsOutOfRange(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ml_pair_threshold must be in [0, 1]');
        (new ConfigLoader())->validate(['ml_pair_threshold' => 1.5]);
    }

    public function testDbSymbolsMethodsMergedFromPerDirectoryConfig(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        file_put_contents($tmp, json_encode([
            'db_symbols' => [
                'methods' => ['myfind' => 'db.read'],
                'functions' => ['myfunc' => 'db.query'],
            ],
        ]));
        try {
            $config = (new ConfigLoader())->load(paths: ['src'], configFile: $tmp);
            $this->assertSame(['myfind' => 'db.read'], $config->dbSymbolsMethods);
            $this->assertSame(['myfunc' => 'db.query'], $config->dbSymbolsFunctions);
        } finally {
            @unlink($tmp);
        }
    }

    public function testDbSymbolsMethodsMergedAdditivelyFromOverrides(): void
    {
        $config = (new ConfigLoader())->load(
            paths: ['src'],
            configFile: null,
            overrides: [
                'db_symbols_methods' => ['myfind' => 'db.read'],
                'db_symbols_functions' => ['myfunc' => 'db.query'],
            ],
        );
        $this->assertSame(['myfind' => 'db.read'], $config->dbSymbolsMethods);
        $this->assertSame(['myfunc' => 'db.query'], $config->dbSymbolsFunctions);
    }

    public function testValidateAcceptsFailOnImpactZero(): void
    {
        (new ConfigLoader())->validate(['fail_on_impact' => 0]);
        $this->expectNotToPerformAssertions();
    }

    public function testValidateAcceptsFailOnImpactPositive(): void
    {
        (new ConfigLoader())->validate(['fail_on_impact' => 100]);
        $this->expectNotToPerformAssertions();
    }

    public function testValidateRejectsFailOnImpactBelowZero(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fail_on_impact must be >= 0');
        (new ConfigLoader())->validate(['fail_on_impact' => -1]);
    }

    public function testValidateAcceptsMaxClustersZero(): void
    {
        (new ConfigLoader())->validate(['max_clusters' => 0]);
        $this->expectNotToPerformAssertions();
    }

    public function testValidateAcceptsMaxClustersPositive(): void
    {
        (new ConfigLoader())->validate(['max_clusters' => 50]);
        $this->expectNotToPerformAssertions();
    }

    public function testValidateRejectsMaxClustersBelowZero(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('max_clusters must be >= 0');
        (new ConfigLoader())->validate(['max_clusters' => -1]);
    }

    public function testFailOnImpactDefaultsToZero(): void
    {
        $config = (new ConfigLoader())->load(paths: ['src'], configFile: null);
        $this->assertSame(0, $config->failOnImpact);
    }

    public function testMaxClustersDefaultsToZero(): void
    {
        $config = (new ConfigLoader())->load(paths: ['src'], configFile: null);
        $this->assertSame(0, $config->maxClusters);
    }

    public function testFailOnImpactOverrideTakesPrecedence(): void
    {
        $config = (new ConfigLoader())->load(
            paths: ['src'],
            configFile: null,
            overrides: ['fail_on_impact' => 200],
        );
        $this->assertSame(200, $config->failOnImpact);
    }

    public function testMaxClustersOverrideTakesPrecedence(): void
    {
        $config = (new ConfigLoader())->load(
            paths: ['src'],
            configFile: null,
            overrides: ['max_clusters' => 10],
        );
        $this->assertSame(10, $config->maxClusters);
    }

    public function testValidateAcceptsDiffBase(): void
    {
        (new ConfigLoader())->validate(['diff_base' => 'origin/main']);
        $this->expectNotToPerformAssertions();
    }

    public function testValidateRejectsDiffBaseEmptyString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('diff_base must be a non-empty string');
        (new ConfigLoader())->validate(['diff_base' => '']);
    }

    public function testValidateRejectsDiffBaseNotString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('diff_base must be a non-empty string');
        (new ConfigLoader())->validate(['diff_base' => 123]);
    }

    public function testDiffBaseDefaultsToNull(): void
    {
        $config = (new ConfigLoader())->load(paths: ['src'], configFile: null);
        $this->assertNull($config->diffBase);
    }

    public function testDiffBaseOverrideTakesPrecedence(): void
    {
        $config = (new ConfigLoader())->load(
            paths: ['src'],
            configFile: null,
            overrides: ['diff_base' => 'HEAD~1'],
        );
        $this->assertSame('HEAD~1', $config->diffBase);
    }
}
