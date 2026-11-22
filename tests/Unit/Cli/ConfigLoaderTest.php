<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Phpdup\Cli\Command;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Parsing\AstCache;
use PhpParser\ParserFactory;

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
            // Canonical public literal: engine-S19a flipped the gate to
            // fail-closed on unresolvable names (ml.example.com never
            // resolved; it only ever passed through the old fail-open
            // hole). A TEST-NET-3 literal keeps this hermetic.
            overrides: ['ml_pair_url' => 'https://203.0.113.7/api'],
        );
        $this->assertSame('https://203.0.113.7/api', $config->mlPairUrl);
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

    // ------------------------------------------------------------------
    // shell-029 — root config read: bounded size cap + throwing JSON
    // ------------------------------------------------------------------

    public function testRootConfigOverSizeCapIsRefused(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        // Valid JSON, just padded past the 1 MiB cap: refusing it proves
        // the SIZE gate fires, and the bounded read proves the file is never
        // slurped whole (the old FUZZ probe: 50 MB → 65.4 s / 438 MB RSS).
        $json = '{"paths":["src"]}';
        file_put_contents($tmp, str_repeat(' ', 2 * 1048576) . $json);
        try {
            try {
                (new ConfigLoader(warnOnSkip: false))->load(paths: ['src'], configFile: $tmp);
                $this->fail('a 2 MB root config must be refused with a size-cap error');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Config file', $e->getMessage());
                $this->assertStringContainsString('exceeds the size cap of 1048576 bytes', $e->getMessage());
                $this->assertStringContainsString($tmp, $e->getMessage());
            }
        } finally {
            @unlink($tmp);
        }
    }

    public function testRootConfigExactlyAtSizeCapIsAccepted(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        $json = '{"paths":["src"]}';
        file_put_contents($tmp, str_repeat(' ', 1048576 - strlen($json)) . $json);
        try {
            $config = (new ConfigLoader(warnOnSkip: false))->load(paths: ['src'], configFile: $tmp);
            $this->assertSame(['src'], $config->paths, 'a file AT the cap is still legal');
        } finally {
            @unlink($tmp);
        }
    }

    public function testRootConfigDeepNestingNamesTheRealJsonException(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        // 700 levels defeats the parser's own 512 depth limit; the user now
        // sees WHY (JSON_THROW_ON_ERROR) instead of the old blanket
        // "Config file is not valid JSON".
        file_put_contents($tmp, str_repeat('[', 700) . str_repeat(']', 700));
        try {
            try {
                (new ConfigLoader(warnOnSkip: false))->load(paths: ['src'], configFile: $tmp);
                $this->fail('over-nested root config must be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('is not valid JSON (Maximum stack depth exceeded)', $e->getMessage());
                $this->assertStringContainsString($tmp, $e->getMessage());
            }
        } finally {
            @unlink($tmp);
        }
    }

    public function testRootConfigSyntaxErrorNamesTheRealJsonException(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        file_put_contents($tmp, '{oops');
        try {
            try {
                (new ConfigLoader(warnOnSkip: false))->load(paths: ['src'], configFile: $tmp);
                $this->fail('malformed root config must be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('is not valid JSON (Syntax error)', $e->getMessage());
                $this->assertStringContainsString($tmp, $e->getMessage());
            }
        } finally {
            @unlink($tmp);
        }
    }

    public function testRootConfigNonObjectJsonIsRefusedWithItsType(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-' . uniqid() . '.json';
        file_put_contents($tmp, 'null');
        try {
            try {
                (new ConfigLoader(warnOnSkip: false))->load(paths: ['src'], configFile: $tmp);
                $this->fail('a JSON null is not a config object');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('is not a JSON object (got null)', $e->getMessage());
                $this->assertStringContainsString($tmp, $e->getMessage());
            }
        } finally {
            @unlink($tmp);
        }
    }

    public function testSkipNotesResetOnEveryLoad(): void
    {
        $loader = new ConfigLoader(warnOnSkip: false);
        $loader->load(paths: ['src'], configFile: null);
        $this->assertSame([], $loader->skipNotes(), 'a clean run records nothing');
    }

    // ---- engine-S25: engine-006 read-path trust gate wiring ----

    public function testTrustInRootCacheIsCliOnlyNotAConfigFileKey(): void
    {
        // Belt (non-vacuous). The opt-in is a CLI flag, deliberately NOT a
        // shapeOverrides/per-directory key, so a poisoned `.phpdup.json`
        // committed into a cloned tree can never silently arm cache reads —
        // trusting a decoy cache has to be an explicit per-invocation act.
        // ConfigLoader::validate() must therefore reject it as unknown.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown config key 'trust_in_root_cache'");
        (new ConfigLoader())->validate(['trust_in_root_cache' => true]);
    }

    public function testCommandRegistersCamelFlagAndNoSnakeAlias(): void
    {
        // Use the repo idiom (Application::add triggers Command::configure()
        // exactly once, same as a real `phpdup analyze` boot) rather than a
        // raw reflection call, which would double-register the paths arg.
        $app = new Application();
        $app->add(new Command());
        $definition = $app->find('analyze')->getDefinition();

        $this->assertTrue($definition->hasOption('trust-in-root-cache'),
            'the kebab CLI flag is registered');
        $this->assertFalse($definition->hasOption('trust_in_root_cache'),
            'no snake_case form leaks into the CLI (it is a config-key shape, not a flag)');
    }

    public function testCommandDetectsCacheInsideScanRoot(): void
    {
        $root = sys_get_temp_dir() . '/phpdup-root-' . uniqid('', true);
        $project = $root . '/project';
        mkdir($project, 0o777, true);
        try {
            $probe = new Command();
            $detect = new \ReflectionMethod($probe, 'cacheResidesInScanRoot');
            $detect->setAccessible(true);
            $in = static fn (string $cache, array $paths): bool => (bool)$detect->invoke($probe, $cache, $paths);

            // The PoC, verbatim: relative default cache + a `.` root.
            $this->assertTrue($in('.phpdup-cache', ['.']),
                'the default relative cache is inside a `.` scan root — the poisoning posture');

            // Absolute cache nested under the (existing) named root.
            $this->assertTrue($in($project . '/.phpdup-cache', [$project]),
                'a cache under the scan root is in-root');
            $this->assertTrue($in($project . '/.phpdup-cache', [$root]),
                'a cache under a parent scan root is in-root too');

            // A cold cache dir reached through `..` (never created on disk)
            // must still normalise lexically to inside the root.
            $this->assertTrue($in($project . '/sub/../.phpdup-cache', [$project]),
                'the lexical walk resolves .. for a not-yet-created cache dir');

            // r1 LOW-1 (kills the foldless mutant M3, which survived the
            // row above): a `..`-hop IN from a FOREIGN absolute prefix.
            // realpath fails (/nonexistent), so the lexical walk must pop
            // the foreign segment and land INSIDE $root → TRUE. A deleted
            // `..` fold leaves the literal '/nonexistent/../…' — which
            // never carries the root prefix → FALSE. The shipped-row above
            // cannot tell the two apart (its string keeps the prefix even
            // unfold); this row is the discriminator.
            $this->assertTrue($in('/nonexistent/../' . ltrim($root, '/') . '/.phpdup-cache', [$root]),
                'a cold ..-hop from a foreign prefix normalises lexically to inside the root');

            // A cache OUTSIDE every scan root is trusted (normal CI posture:
            // point cache_dir at a shared temp dir → no refusal, no notice).
            $this->assertFalse($in($root . '/elsewhere-cache', [$project]),
                'a cache outside the scan roots is not refused');
            $this->assertFalse($in('/var/tmp/phpdup-shared-cache', [$project]),
                'an absolute external cache is not refused');
        } finally {
            @rmdir($project);
            @rmdir($root);
        }
    }

    // ---- engine-S25 r1: Command-level trust-gate pins (MEDIUM-1, LOW-2) ----

    /**
     * MEDIUM-1: the whole Command leg — arming, the notice, honest output
     * — pinned end to end. Before this test, deleting the notice block
     * (mutant M2) or arming after dispatch() was invisible to all 6,404
     * tests: users would suffer exactly the silent cold miss this step's
     * doctrine forbids. Plant the reviewer's PoC verbatim (twin.php's
     * parsed stmts under real.php's content key, re-minted fresh before
     * every run) inside a REAL in-root `.phpdup-cache`, then run
     * `analyze` through the same Command::execute() path the CLI takes
     * and pin all three legs: the refusal is armed (zero fabricated
     * clusters in the JSON), the warning prints (exactly one notice), and
     * the honest duplicate is still reported. The `--trust-in-root-cache`
     * twin proves the mint is real and the gate is the ONLY barrier — the
     * honest run's notContains assertions are therefore non-vacuous
     * in-test, not a lucky empty corpus.
     */
    public function testCommandWarnsAndRefusesAPlantedInRootCacheEndToEnd(): void
    {
        $dir = $this->scratchDir();
        $corpus = $dir . '/corpus';
        mkdir($corpus, 0o777, true);
        // Victim: unique tiny body — honestly it joins no cluster at all.
        file_put_contents($corpus . '/real.php', "<?php\nfunction real_helper(): int { return 4242; }\n");
        // Decoy source AND its honest (unclustered) twin file.
        file_put_contents($corpus . '/twin.php',
            "<?php\nfunction decoy_fn(int \$m): int {\n    \$acc = 0;\n    for (\$j = 0; \$j < \$m; \$j++) {\n        \$acc += \$j;\n    }\n    return \$acc;\n}\n");
        // Ground truth: a genuine EXACT pair so "the report ran" and
        // "only the real cluster is reported" are both assertable.
        foreach (['alpha', 'beta'] as $f) {
            file_put_contents($corpus . '/' . $f . '.php',
                "<?php\nfunction shared_util(int \$n): int {\n    \$total = 0;\n    for (\$i = 0; \$i < \$n; \$i++) {\n        \$total += \$i;\n    }\n    return \$total;\n}\n");
        }
        $configPath = $dir . '/phpdup-s25.json';
        file_put_contents($configPath, json_encode(['cache_dir' => $corpus . '/.phpdup-cache']) ?: '{}');

        // ── default posture: armed refusal + notice + honest-only report ──
        $this->mintDecoy($corpus);
        [$exit, $display, $json] = $this->analyzeCorpus($corpus, $configPath, $dir . '/out-default.json');
        self::assertSame(0, $exit, 'refuse-with-notice is a cold run, exit 0 by doctrine: ' . $display);
        self::assertSame(1, substr_count($display, 'ignoring the AST cache'),
            'exactly one plain notice — the M2 (notice-block-deleted) mutant flips RED here: ' . $display);
        self::assertStringContainsString('--trust-in-root-cache', $display, 'the notice names the way out');
        self::assertStringContainsString('shared_util', $json, 'the honest cluster is still found and reported');
        self::assertStringContainsString('alpha.php', $json);
        self::assertStringContainsString('beta.php', $json);
        // The fabrication marker is structural: the blob stores twin.php's
        // tree under real.php's content key, so "served" ⇔ a cluster member
        // named decoy_fn reported AT real.php. (The string 'decoy_fn' alone
        // is legitimate — twin.php genuinely declares it.)
        self::assertSame([], $this->fabricatedMembers($json),
            'the planted blob must not surface — arming-after-dispatch flips RED here');
        self::assertStringNotContainsString('real.php', $json,
            'the victim stays out of every reported member when reads are refused');

        // ── opt-in legacy posture: decoy served (in-test non-vacuity) ──
        $this->mintDecoy($corpus); // the cold pass repopulated honest blobs — re-plant
        [$exit, $display, $json] = $this->analyzeCorpus($corpus, $configPath, $dir . '/out-trust.json',
            ['--trust-in-root-cache' => true]);
        self::assertSame(0, $exit, $display);
        self::assertStringNotContainsString('ignoring the AST cache', $display, 'an opted-in run must not nag');
        self::assertNotEmpty($this->fabricatedMembers($json),
            'the mint is real: trusted reads serve the forged tree — the PoC (decoy_fn @ real.php) reproduces');

        // ── --workers 2 twin (H3): forked workers inherit the armed gate ──
        if (function_exists('pcntl_fork')) {
            $this->mintDecoy($corpus);
            [$exit, $display, $json] = $this->analyzeCorpus($corpus, $configPath, $dir . '/out-workers.json',
                ['--workers' => 2]);
            self::assertSame(0, $exit, $display);
            self::assertSame(1, substr_count($display, 'ignoring the AST cache'),
                'the notice arms pre-fork; workers must inherit it — RED if arming moves after dispatch');
            self::assertStringContainsString('shared_util', $json);
            self::assertSame([], $this->fabricatedMembers($json), 'forked reads honour the gate');
            self::assertStringNotContainsString('real.php', $json);
        }
    }

    public function testTrustAndCacheKeysAreNotAdmittedPerDirectory(): void
    {
        // LOW-2: the posture depends on both keys being root/CLI-only —
        // a committed `.phpdup.json` must never opt a poisoned tree into
        // trusting its own decoy cache (trust_in_root_cache), nor steer
        // the blob store per-directory (cache_dir). Planted one at a
        // time, shapeOverrides must shape to NOTHING.
        //
        // Direct admission-table pin rather than extending
        // PerDirOverrideBeltTest's loop: the belt's emitted-set
        // derivation is payload-driven, so a newly admitted key stays
        // invisible until the payload grows (the belt docblock's own
        // "blind, not clean" disclosure — mutant M4 survived the suite
        // for exactly that reason). Feeding the sentinel and demanding
        // [] reproduces the same non-admission loudly. Belt-payload
        // extension stays shell-S22's owner surface — banked report row.
        $loader = new ConfigLoader();
        $shape = new \ReflectionMethod($loader, 'shapeOverrides');

        foreach (['trust_in_root_cache' => true, 'cache_dir' => '/belt/planted-cache'] as $key => $sentinel) {
            /** @var array<string, mixed> $emitted */
            $emitted = $shape->invoke($loader, [$key => $sentinel]);
            self::assertSame([], $emitted,
                "shapeOverrides must not admit '$key' per-directory (engine-S25 posture)");
        }
    }

    // ---- engine-S25 r1 helpers ----

    private ?string $s25Scratch = null;

    protected function tearDown(): void
    {
        // The trust gate is process-wide static state — never let a run
        // leak arming into a later test class (AstCacheTest's convention).
        AstCache::refuseInRootCacheReads(false);
        if ($this->s25Scratch !== null) {
            self::deleteTree($this->s25Scratch);
            $this->s25Scratch = null;
        }
    }

    private function scratchDir(): string
    {
        if ($this->s25Scratch === null) {
            $dir = sys_get_temp_dir() . '/phpdup-s25r1-' . getmypid() . '-' . spl_object_id($this);
            mkdir($dir, 0o777, true);
            $this->s25Scratch = $dir;
        }

        return $this->s25Scratch;
    }

    /**
     * Wipe the cache dir so each run sees ONLY the planted blob, then
     * forge: twin.php's parsed stmts stored under real.php's content key
     * (same mint idiom as AstCacheTest — the repo's own AstCache::put).
     */
    private function mintDecoy(string $corpus): void
    {
        $cacheDir = $corpus . '/.phpdup-cache';
        self::deleteTree($cacheDir);
        $parser = (new ParserFactory())->createForHostVersion();
        $stmts = $parser->parse((string)file_get_contents($corpus . '/twin.php'));
        self::assertIsArray($stmts);
        (new AstCache($cacheDir))->put($corpus . '/real.php', $stmts);
    }

    /**
     * Structural fabrication marker: every reported cluster member that
     * names the decoy function at the VICTIM's path — the exact shape the
     * poisoned blob produces ('decoy_fn' served under real.php's content
     * key). Path-independent string checks would false-positive on the
     * legitimate decoy_fn that twin.php really declares.
     *
     * @return list<array<string, mixed>>
     */
    private function fabricatedMembers(string $json): array
    {
        $report = json_decode($json, true);
        self::assertIsArray($report, 'the --json output must be valid JSON');
        $fabricated = [];
        foreach ($report['clusters'] ?? [] as $cluster) {
            foreach ($cluster['members'] ?? [] as $member) {
                if (($member['name'] ?? null) === 'decoy_fn'
                    && str_ends_with((string)($member['file'] ?? ''), '/real.php')) {
                    $fabricated[] = $member;
                }
            }
        }

        return $fabricated;
    }

    /**
     * Run `analyze` exactly as a CLI user would through the shared
     * Command::execute() path.
     *
     * @param array<string, mixed> $extra
     * @return array{0: int, 1: string, 2: string} [exit, display, json]
     */
    private function analyzeCorpus(string $corpus, string $configPath, string $jsonPath, array $extra = []): array
    {
        @unlink($jsonPath);
        $app = new Application('phpdup', '0.1.0');
        $app->add(new Command());
        $tester = new CommandTester($app->find('analyze'));
        $exit = $tester->execute(array_merge([
            'paths'                => [$corpus],
            '--config'             => $configPath,
            '--json'               => $jsonPath,
        ], $extra));

        return [$exit, $tester->getDisplay(), (string)@file_get_contents($jsonPath)];
    }

    private static function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
