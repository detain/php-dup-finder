<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Clustering\Clusterer;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Flag-train coverage for --candidate-budget / config "candidate_budget"
 * (improve_logic.md §3.6 item 0.5, WAVE-1 leg P0d).
 *
 * Pins the full semantics chain: the Config default equals the Clusterer
 * constant (so raising the constant stays a single-site decision), the
 * 0 = unlimited sentinel, negative rejection at Config construction,
 * CLI string coercion through ConfigLoader::NUMERIC_KEYS (the m1 class
 * of bug — an uncoerced raw string reaching strict Config params), the
 * trim policy for numeric values, config-file validation, and that the
 * knob actually arms the generation guard end-to-end through ClusterStage.
 */
final class CandidateBudgetFlagTest extends TestCase
{
    public function testDefaultEqualsClustererGuardCap(): void
    {
        $config = new Config(paths: ['/tmp'], exclude: []);
        self::assertSame(Clusterer::MAX_CANDIDATE_PAIRS, $config->candidateBudget);
        self::assertSame(60_000_000, $config->candidateBudget, 'Documented default value');
    }

    public function testZeroSentinelMeansUnlimitedAndIsAccepted(): void
    {
        self::assertSame(0, (new Config(paths: ['/tmp'], exclude: [], candidateBudget: 0))->candidateBudget);
    }

    public function testNegativeBudgetRejectedAtConfigConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candidate_budget');
        new Config(paths: ['/tmp'], exclude: [], candidateBudget: -1);
    }

    public function testCliStringOverrideIsCoercedToInt(): void
    {
        // Symfony Console hands VALUE_REQUIRED options over as raw strings;
        // without the NUMERIC_KEYS row this dies with a TypeError (m1 bug).
        $config = (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => '50']);
        self::assertSame(50, $config->candidateBudget);
    }

    public function testWhitespaceAroundNumericValueIsTrimmedAndAccepted(): void
    {
        // Lane-pinned coercion policy (f326b06): surrounding whitespace is
        // not an error for numeric flag values.
        $config = (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => " 42 
"]);
        self::assertSame(42, $config->candidateBudget);
    }

    public function testZeroViaCliStringOverrideKeepsSentinel(): void
    {
        $config = (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => '0']);
        self::assertSame(0, $config->candidateBudget);
    }

    public function testGarbageCliValueRejectsWithFriendlyInvalidArgument(): void
    {
        // Command.php surfaces RuntimeException|InvalidArgumentException as exit 2.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candidate_budget');
        (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => 'abc']);
    }

    public function testNegativeCliValueRejectsWithFriendlyInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => '-5']);
    }

    public function testBeyondInt64CliValueFailsLoudInsteadOfSaturating(): void
    {
        // ft-n1: twenty nines used to pass the integer-shape regex and
        // (int)-saturate to PHP_INT_MAX — the generation guard read that
        // as "unlimited" and the knob silently disarmed. Now rejected at
        // the coercion boundary (same friendly exit-2 class as 'abc').
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('64-bit');
        (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => '99999999999999999999']);
    }

    public function testZeroPaddedInMagnitudeValueIsAcceptedByTrimPolicy(): void
    {
        // [n]-1 pin: trim-policy symmetry. The shape regex admits leading
        // zeros and textBeyondInt64 ltrims them, so padded values are
        // treated exactly as their unpadded twins — never rejected for
        // the padding alone.
        $config = (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => '00000009999']);
        self::assertSame(9999, $config->candidateBudget);
    }

    public function testZeroPaddedBeyondInt64StillFailsLoud(): void
    {
        // The padding does not smuggle an out-of-range value past the
        // ft-n1 guard: ltrim exposes the 19 nines and the magnitude
        // compare rejects it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('64-bit');
        (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => '0009999999999999999999']);
    }

    public function testZeroPaddedInt64MaxIsAcceptedOnlyThroughTrimBranch(): void
    {
        // Load-bearing pin (replaces the vacuity of the short-padding
        // truth-pins above): 10 leading zeros make the raw string 29
        // characters, so an UNTRIMMED length compare (29 > 19) would
        // reject it outright. The accept below is produced solely by the
        // ltrim('0') branch collapsing the magnitude to exactly
        // PHP_INT_MAX's digit count — revert that trim and this pin goes
        // red, which is the non-vacuity proof.
        $padded = str_repeat('0', 10) . (string) PHP_INT_MAX;
        $config = (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => $padded]);
        self::assertSame(PHP_INT_MAX, $config->candidateBudget);
    }

    public function testPaddedInt64BoundaryPairIsolatesTrimLexicographicCompare(): void
    {
        // Mirror pair, 23 raw characters each (4 pad + 19 digits): with no
        // trim the length branch rejects BOTH identically, so anything
        // this pair proves happens only post-trim — both sides land on the
        // 19-digit cap length and the lexicographic strcmp decides.
        // PHP_INT_MAX stays in range; MAX+1 (held as text, never a PHP
        // int) fails loud. Accept first, reject second: the two inputs
        // differ ONLY in the final digit, isolating the compare in both
        // directions.
        $inRange = '0000' . (string) PHP_INT_MAX;
        $config = (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => $inRange]);
        self::assertSame(PHP_INT_MAX, $config->candidateBudget);

        $aboveRange = '0000' . '9223372036854775808';
        try {
            (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => $aboveRange]);
            self::fail('padded PHP_INT_MAX+1 must fail loud, not saturate');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('64-bit', $e->getMessage());
        }
    }

    public function testInt64BoundariesAreInRangeAndHandledDownstream(): void
    {
        // Exactly PHP_INT_MAX is representable, so coercion must accept it
        // (a huge-but-finite budget is the caller's legitimate problem).
        $config = (new ConfigLoader())->load(
            [self::scratchDir()],
            null,
            ['candidate_budget' => (string)PHP_INT_MAX],
        );
        self::assertSame(PHP_INT_MAX, $config->candidateBudget);

        // -9223372036854775808 (PHP_INT_MIN's magnitude) fits the signed
        // range; it fails one layer later at the Config min>=0 bound —
        // proving the overflow guard never acts as a blanket negative veto.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candidate_budget');
        (new ConfigLoader())->load([self::scratchDir()], null, ['candidate_budget' => '-9223372036854775808']);
    }

    public function testConfigFileDomainValidation(): void
    {
        $loader = new ConfigLoader();

        // Known key: must not throw "Unknown config key".
        $loader->validate(['candidate_budget' => 123456789]);
        $loader->validate(['candidate_budget' => 0]);

        $this->expectException(\RuntimeException::class);
        $loader->validate(['candidate_budget' => -1]);
    }

    public function testConfigFileRejectsNonIntegerBudget(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['candidate_budget' => 'lots']);
    }

    public function testDefaultConfigThroughLoaderKeepsClustererCap(): void
    {
        $config = (new ConfigLoader())->load([self::scratchDir()], null, []);
        self::assertSame(Clusterer::MAX_CANDIDATE_PAIRS, $config->candidateBudget);
    }

    public function testKnobArmsGenerationGuardEndToEnd(): void
    {
        $dir = self::makeChainCorpus();

        $config = new Config(
            paths: [$dir],
            exclude: [],
            minBlockSize: 2,
            maxDocumentFrequency: 1.0,
            workers: 1,
            lazyAst: false,
            candidateBudget: 1, // 3 blocks => >= 2 candidate pairs => halt at pair #2
        );

        try {
            self::drainToClustering($config);
            self::fail('expected CandidateBudgetException at the armed budget');
        } catch (CandidateBudgetException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('budget of 1 pair', $msg);
            self::assertStringContainsString('--candidate-budget', $msg, 'Halt must name the live knob');
            self::assertStringContainsString('--max-df', $msg);
        }
    }

    public function testDefaultBudgetRunsSameCorpusToCompletion(): void
    {
        $dir = self::makeChainCorpus();

        $config = new Config(
            paths: [$dir],
            exclude: [],
            minBlockSize: 2,
            maxDocumentFrequency: 1.0,
            workers: 1,
            lazyAst: false,
        );

        $state = self::drainToClustering($config);
        self::assertGreaterThanOrEqual(2, $state->candidatePairs, 'corpus must seed pairs for the armed test to mean anything');
    }

    /** Small hermetic dir: load() walks paths for .phpdup.json — never point it at /tmp. */
    private static function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-candbudget-scratch';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        return $dir;
    }

    // ---- m1 fix pins: Config::withOverrides must consume AND preserve ----

    public function testWithOverridesConsumesCandidateBudget(): void
    {
        $root = new Config(paths: [self::scratchDir()], exclude: []);
        self::assertSame(7, $root->withOverrides(['candidate_budget' => 7])->candidateBudget);
        // Per-dir JSON may carry numeric strings; the cast must hold.
        self::assertSame(9, $root->withOverrides(['candidate_budget' => '9'])->candidateBudget);
    }

    public function testDerivedConfigNeverResetsRootBudget(): void
    {
        // Anti-reset (the latent half of m1): a derived Config built from
        // UNRELATED overrides must carry the root's explicit budget. Before
        // the fix, withOverrides omitted the named arg and silently rewound
        // to the 60M default — neutering the memory guard on every derivation.
        $root = new Config(paths: [self::scratchDir()], exclude: [], candidateBudget: 123);
        self::assertSame(123, $root->withOverrides(['min_block_size' => 4])->candidateBudget);
        self::assertSame(123, $root->withOverrides([])->candidateBudget);
    }

    public function testPerDirectoryCandidateBudgetReachesDerivedConfig(): void
    {
        // End-to-end of the discover -> shapeOverrides -> withOverrides chain:
        // a per-directory .phpdup.json candidate_budget must not be silently
        // dropped from the derived per-file Config.
        $dir = self::makeChainCorpus();
        mkdir($dir . '/sub', 0o777, true);
        file_put_contents($dir . '/sub/.phpdup.json', '{"candidate_budget": 5}');

        $root = (new ConfigLoader())->load([$dir], null, []);
        self::assertSame(5, $root->effectiveFor($dir . '/sub/f0.php')->candidateBudget, 'per-dir value consumed');
        self::assertSame(
            Clusterer::MAX_CANDIDATE_PAIRS,
            $root->effectiveFor($dir . '/f0.php')->candidateBudget,
            'files outside the sub-directory keep the root budget',
        );
    }

    private static function drainToClustering(Config $config): PipelineState
    {
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false))->run($state, $out);
        return $state;
    }

    /** Three tiny files with byte-identical normalized bodies: 3 blocks => >=3 candidate pairs. */
    private static function makeChainCorpus(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-candbudget-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o777, true);
        $body = "<?php\nfunction probe_%d(\$x) {\n    \$y = \$x + 1;\n    return \$y;\n}\n";
        for ($i = 0; $i < 3; $i++) {
            file_put_contents($dir . "/f$i.php", sprintf($body, 0));
        }
        return $dir;
    }
}
