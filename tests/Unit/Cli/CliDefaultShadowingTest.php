<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * NN1 (plan item 5.2 rider batch): a bare `phpdup analyze <paths>` must
 * inject ZERO config-shadowing overrides. --scorer used to carry a
 * non-null Symfony default ('default'), so parseCliOverrides() always set
 * $overrides['scorer'], and OverrideResolver's precedence
 * (overrides > config file > defaults) silently SHADOWED any "scorer"
 * pinned in the config file.
 *
 * The sweep that closed the class: every other option with a non-null
 * default (--limit 50, --pager 'never', --theme 'ansi') is render-only —
 * consumed in parseRuntimeOptions(), never written into the override dict,
 * and absent from ConfigLoader's flat/validated key surface. Pinned below
 * so a future flag with a default can't re-open the hole unnoticed.
 */
final class CliDefaultShadowingTest extends TestCase
{
    public function testScorerOptionCarriesNoSymfonyDefault(): void
    {
        $definition = (new Command())->getDefinition();

        $this->assertNull(
            $definition->getOption('scorer')->getDefault(),
            'a VALUE_REQUIRED option must default to null or it shadows the config file (NN1)',
        );
    }

    public function testBareInvocationInjectsNoOverrides(): void
    {
        $overrides = $this->parseCliOverrides([]);

        $this->assertSame([], $overrides, 'a bare CLI invocation must leave every config-file value in charge');
    }

    public function testRejectedInputIsDistinguishedFromBareInvocation(): void
    {
        // The old execute() guard treated `overrides === []` as the error
        // sentinel; with NN1 fixed, [] is a legal bare result — rejections
        // must return null or a clean run would exit 2 silently.
        $this->assertNull($this->parseCliOverrides(['--scorer' => 'nonsense']));
    }

    public function testInvalidRuntimeChoiceIsRejectedNotIgnored(): void
    {
        // shell-S24 (php-dup-finder-shell-013): parseRuntimeOptions carries
        // the SAME null-means-refused idiom now — a bad --stage used to
        // print and run the full pipeline anyway. Pinned so the idiom the
        // shell-S25/S27/S28 chains reuse stays an exit-2 door, and so the
        // render-only validated options never drift into the override dict
        // (their shadowing contract above is untouched by the validation).
        $command = new Command();
        $input = new ArrayInput(['--stage' => 'blah'], $command->getDefinition());

        $method = new \ReflectionMethod(Command::class, 'parseRuntimeOptions');
        $this->assertNull($method->invoke($command, $input, new NullOutput(), false));

        $validInput = new ArrayInput(['--stage' => 'scanning'], $command->getDefinition());
        $parsed = $method->invoke($command, $validInput, new NullOutput(), false);
        $this->assertIsArray($parsed, 'a valid stage still parses');
        $this->assertNotNull($parsed['stopAfter'], '--stage=scanning must set the stop point');
    }

    public function testExplicitScorerStillFlowsThrough(): void
    {
        $overrides = $this->parseCliOverrides(['--scorer' => 'ir']);

        $this->assertSame(['scorer' => 'ir'], $overrides, 'the flag works when actually passed');
    }

    public function testRenderOnlyDefaultedOptionsNeverReachOverrides(): void
    {
        // --limit/--pager/--theme keep their non-null defaults (they are
        // presentation settings read via parseRuntimeOptions, not Config
        // keys) — the guard is that they never leak into the override dict.
        $overrides = $this->parseCliOverrides([
            '--limit'  => '7',
            '--pager'  => 'always',
            '--theme'  => 'nord',
            '--scorer' => 'default',
        ]);

        $this->assertSame(['scorer' => 'default'], $overrides);
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>|null
     */
    private function parseCliOverrides(array $params): ?array
    {
        $command = new Command();
        $input = new ArrayInput($params, $command->getDefinition());

        $method = new \ReflectionMethod(Command::class, 'parseCliOverrides');

        /** @var array<string,mixed>|null $overrides */
        $overrides = $method->invoke($command, $input, new NullOutput());
        return $overrides;
    }

    // ------------------------------------------------------------------
    // once-S19 (php-dup-finder-once-022): the default-command surface.
    //
    // `bin/phpdup <path>` used to die with Symfony's exit-1 "Command is
    // not defined" while Command::execute()'s own missing-path hint
    // taught exactly that broken shape, and a bare `bin/phpdup` fell
    // into analyze's exit-2 path door. bin/phpdup now carries a
    // pre-dispatch routing guard (once-S19 comment there — the literal
    // setDefaultCommand(..., true) is NOT shippable: Symfony's
    // singleCommand mode short-circuits every dispatch to the default
    // command and drops the `command` argument entirely, making
    // init/serve/self:update unreachable). These pins run the REAL
    // entry script against throwaway /tmp trees so the contract stays
    // behavioural, not textual.

    private const DUP_CLASS = <<<'PHP'
        <?php
        class %CLASS% {
            public function handle($x)
            {
                $a = $x + 1;
                $b = $a * 2;
                $c = $b - 3;
                $d = $c ^ 7;
                $e = $d % 5;
                $f = $e . 'z';
                $g = strlen((string) $f);
                $h = $g + $d;
                $i = $h % 11;
                $j = $i * 3 - $e;
                $k = ($j + $c) % 17;
                $l = $k ^ $b;
                $m = $l + $a - $g;
                $n = abs($m) % 29;
                $o = $n + 1;
                $p = $o * 2 % 37;
                return [$p, $o, $n, $m, $l, $k, $j, $i, $h, $g, $f, $e, $d, $c, $b, $a];
            }
        }
        PHP;

    /** @var list<string> */
    private array $s19ScratchDirs = [];

    public function testDefaultCommandFormBehavesExactlyLikeAnalyze(): void
    {
        $tree = $this->makeS19DupTree();
        $dirA = $this->makeS19ScratchDir();
        $dirB = $this->makeS19ScratchDir();
        $jsonA = $dirA . '/default-form.json';
        $jsonB = $dirB . '/explicit-form.json';

        [$codeA, , $errA] = $this->runPhpDup([$tree, '--json', $jsonA], $dirA);
        [$codeB] = $this->runPhpDup(['analyze', $tree, '--json', $jsonB], $dirB);

        $this->assertSame(
            0,
            $codeA,
            'the documented path-as-first-argument form must RUN analyze, not die with "Command is not defined"; stderr was: ' . $errA
        );
        $this->assertSame(0, $codeB, 'the explicit analyze form is the reference run');
        $this->assertTrue(is_file($jsonA), 'the default form wrote its --json report');
        $this->assertTrue(is_file($jsonB));

        $reportA = json_decode((string) file_get_contents($jsonA), true);
        $reportB = json_decode((string) file_get_contents($jsonB), true);
        $this->assertIsArray($reportA);
        $this->assertIsArray($reportB);
        $this->assertGreaterThanOrEqual(
            1,
            (int) $reportA['summary']['clusters'],
            'the scratch tree carries a real clone pair — analyze must find it through the default form'
        );
        $this->assertSame(
            json_encode($reportB['clusters']),
            json_encode($reportA['clusters']),
            'default form and explicit analyze must produce the identical cluster payload (only cache-warmth counters may differ)'
        );
    }

    public function testBareInvocationShowsTheCommandListingNotAnError(): void
    {
        $cwd = $this->makeS19ScratchDir();
        [$code, $stdout] = $this->runPhpDup([], $cwd);

        $this->assertSame(
            0,
            $code,
            'a bare invocation falls to the command listing (exit 0), not analyze\'s exit-2 path door (the old behaviour)'
        );
        $this->assertStringContainsString('Available commands', $stdout, 'no-args users must be shown the command surface');
        foreach (['analyze', 'init', 'serve', 'self:update', 'completion'] as $name) {
            $this->assertStringContainsString($name, $stdout, "the listing must surface the '{$name}' sub-command");
        }
    }

    public function testFlagOnlyInvocationWithoutPathsKeepsTheAdmissionDoor(): void
    {
        // shell-S24 doctrine, untouched by the guard: flags without any
        // path are a usage error (exit 2) — NOT the pre-fix Command-not-
        // defined exit 1, and NOT the help fallback reserved for a truly
        // empty invocation.
        $cwd = $this->makeS19ScratchDir();
        [$code] = $this->runPhpDup(['--exact-only'], $cwd);

        $this->assertSame(2, $code, 'a flag-only no-path run stays at analyze\'s exit-2 admission door');
    }

    public function testNamedSubcommandsStayReachableThroughTheGuard(): void
    {
        $cwd = $this->makeS19ScratchDir();
        [$code] = $this->runPhpDup(['init'], $cwd);
        $this->assertSame(0, $code, '`init` must still dispatch to InitCommand');
        $this->assertTrue(is_file($cwd . '/phpdup.json'), 'named routing survived the guard: the scaffold is real');
        $this->assertIsArray(json_decode((string) file_get_contents($cwd . '/phpdup.json'), true));

        [$vCode] = $this->runPhpDup(['-V'], $cwd);
        $this->assertSame(0, $vCode, '--version short-circuits before command resolution; the guard must not disturb it');

        [$sCode, $sOut] = $this->runPhpDup(['serve', '--help'], $cwd);
        $this->assertSame(0, $sCode);
        $this->assertStringContainsString('--host', $sOut, '`serve --help` must reach the SERVE command, never a routed analyze');
    }

    public function testDirectoryNamedLikeASubcommandIsAddressableWithPathSeparation(): void
    {
        $tree = $this->makeS19ScratchDir();
        mkdir($tree . '/init', 0o777, true);
        // A non-empty collision dir: 0-file scans short-circuit before the
        // reporters, so only a real corpus proves analyze ran.
        foreach (['GammaDup', 'DeltaDup'] as $class) {
            file_put_contents($tree . '/init/' . $class . '.php', str_replace('%CLASS%', $class, self::DUP_CLASS));
        }
        $json = $tree . '/collision.json';

        [$code] = $this->runPhpDup(['./init', '--json', $json], $tree);

        $this->assertSame(0, $code, 'the ./name form analyzes the directory instead of running the same-named sub-command');
        $this->assertTrue(is_file($json), 'the run wrote analyze\'s --json report, so it really was analyze');
        $report = json_decode((string) file_get_contents($json), true);
        $this->assertIsArray($report);
        $this->assertGreaterThanOrEqual(1, (int) $report['summary']['clusters'], 'the duplicate pair inside ./init was scanned by analyze');
        $this->assertFalse(is_file($tree . '/phpdup.json'), 'InitCommand must NOT have run — the ./init token reached analyze as a path');
    }

    // ------------------------------------------------------------------
    // shell-S28 (php-dup-finder-shell-039 / shell-055 / once-S31-r1 F-2):
    // --dry-run is no longer a registered-but-never-read phantom, the
    // grouped help no longer drifts from the option surface, and argv scan
    // roots are absolutized at the Command boundary so a relative root
    // resolves against the invocation CWD — never the phar archive root.

    public function testGroupedHelpListsEveryRegisteredOption(): void
    {
        // shell-055: the help cheat-sheet had drifted (--pager/--debug-log
        // missing). Pinned as a set relation, not a string grep, so ANY new
        // option without a grouped-help home fails here, not in a user's
        // terminal.
        $command = new Command();
        $help = $command->getHelp();
        $this->assertNotEmpty($help);

        $missing = [];
        foreach ($command->getDefinition()->getOptions() as $name => $option) {
            if (!str_contains($help, '--' . $name)) {
                $missing[] = $name;
            }
        }
        $this->assertSame([], $missing, 'every registered option must appear in the grouped help');
    }

    public function testDryRunDefaultKeepsThePreviewPosture(): void
    {
        // shell-039: --dry-run used to be invisible to execute() —
        // applyDryRun was hardcoded true regardless. The DEFAULT posture is
        // still preview (F1a's safe direction), pinned here so the wiring
        // cannot regress to "flag parsed, value dropped".
        $reportArgs = $this->runtimeReportArgs(['--apply' => true]);

        $this->assertTrue($reportArgs['applyDir'] === 'refactored', '--apply engages the apply posture');
        $this->assertTrue($reportArgs['applyDryRun'], 'absent --dry-run keeps the preview posture (apply.diff)');
    }

    public function testDryRunFalseFlipsToTheRealApplyPosture(): void
    {
        $reportArgs = $this->runtimeReportArgs(['--apply' => true, '--dry-run' => 'false']);

        $this->assertSame('refactored', $reportArgs['applyDir']);
        $this->assertFalse($reportArgs['applyDryRun'], '--dry-run=false must open the F1b branch');
    }

    public function testDryRunAcceptsTheDocumentedBooleanSpellings(): void
    {
        $truthy = ['true', '1', 'yes', 'on', 'TRUE', 'Yes'];
        $falsy = ['false', '0', 'no', 'off', 'FALSE', 'No'];
        foreach ($truthy as $raw) {
            $reportArgs = $this->runtimeReportArgs(['--apply' => true, '--dry-run' => $raw]);
            $this->assertTrue($reportArgs['applyDryRun'], "--dry-run={$raw} must read preview");
        }
        foreach ($falsy as $raw) {
            $reportArgs = $this->runtimeReportArgs(['--apply' => true, '--dry-run' => $raw]);
            $this->assertFalse($reportArgs['applyDryRun'], "--dry-run={$raw} must read real-apply");
        }

        // The bare native-true form (what a post-normalizer --dry-run=true
        // binds as, and what ArrayInput callers pass directly) reads preview.
        $reportArgs = $this->runtimeReportArgs(['--apply' => true, '--dry-run' => true]);
        $this->assertTrue($reportArgs['applyDryRun'], 'native bool true must flow, not fall through the door');
    }

    public function testDryRunRejectsNonBooleanWithoutRunning(): void
    {
        // shell-S24 door idiom: null means refused (exit 2), never run-on.
        $this->assertNull($this->parseRuntime(['--apply' => true, '--dry-run' => 'maybe']));
    }

    public function testDryRunNeverLeaksIntoOverrides(): void
    {
        // render-only flag (presentation posture, not a Config key) — the
        // NN1 shadowing family applies.
        $overrides = $this->parseCliOverrides(['--dry-run' => 'false']);

        $this->assertSame([], $overrides, '--dry-run must never reach the override dict');
    }

    public function testArgvScanRootsAreAbsolutizedAtTheBoundary(): void
    {
        // once-S31-r1 F-2 cure seam. Inside an executed phar, relative file
        // calls resolve against the ARCHIVE (is_dir('dup') false, getcwd()
        // normal), so the shipped form scanned 0 files at exit 0. The cure
        // anchors every argv spelling on the invocation CWD once, here, at
        // the Command boundary — FileScanner below never sees a relative
        // token again. Chdir into a scratch tree so the anchor is a
        // different directory than the repo: a leak through realpath of
        // the repo would still "work" and hide the bug, so this pins the
        // CWD-anchoring property directly.
        $tree = $this->makeS19ScratchDir();
        mkdir($tree . '/dup', 0o777, true);
        $original = getcwd();
        $this->assertIsString($original);
        chdir($tree);
        try {
            $roots = $this->absolutize(['dup', './dup/', 'missing/rel', $tree . '/dup', '', '.', '..']);

            $this->assertSame($tree . '/dup', $roots[0], 'a bare relative root absolutizes on the invocation CWD');
            $this->assertSame($tree . '/dup', $roots[1], './x/ and x arrive at the same absolute');
            $this->assertSame($tree . '/missing/rel', $roots[2], 'a not-yet-existing relative root keeps a real-filesystem absolute spelling (never phar://)');
            $this->assertStringNotContainsString('phar://', $roots[2]);
            $this->assertSame($tree . '/dup', $roots[3], 'an absolute root passes through canonical');
            $this->assertSame('', $roots[4], 'the empty-string entry keeps its pass-through posture');
            $this->assertSame($tree, $roots[5], "'.` anchors on the CWD");
            $this->assertSame(dirname($tree), $roots[6], "'..' walks above the CWD");
            foreach ($roots as $i => $root) {
                if ($root === '') {
                    continue;
                }
                $this->assertStringStartsWith('/', $root, "root #{$i} must be absolute at admission");
                $this->assertFalse(str_starts_with($root, 'phar://'), "root #{$i} must never resolve into the archive");
            }
        } finally {
            chdir($original);
        }
    }

    public function testRelativeRootThroughTheRealLauncherReportsCwdAnchoredAbsolutePaths(): void
    {
        // shell-S28 r1 MEDIUM-1: the DISCOVERY half of the phar cure cannot
        // be belted from the checkout (bin-form resolves a relative root
        // with OR without the boundary call — identical file counts), but
        // the SHAPE that call hands downstream is exclusive to it. execute()
        // feeds the absolutized list into Config, JsonReporter::build()
        // re-emits it verbatim as config.paths, and member files grow from
        // the same roots. Drop `$paths = $this->absoluteArgvScanRoots(...)`
        // from the call site (method body intact) and this run reports the
        // typed relative spelling instead — RED without a single count
        // moving. This is the shell-039 defect-shape (wiring registered,
        // never observable) pinned against silent removal.
        $cwd = $this->makeS19ScratchDir();
        $anchor = realpath($cwd);
        $this->assertIsString($anchor, 'the scratch cwd must resolve for the CWD-anchor contract');
        mkdir($cwd . '/dup/src', 0o777, true);
        foreach (['AlphaDup', 'BetaDup'] as $class) {
            file_put_contents($cwd . '/dup/src/' . $class . '.php', str_replace('%CLASS%', $class, self::DUP_CLASS));
        }
        $json = $cwd . '/rel-root.json';

        [$code, , $err] = $this->runPhpDup(['dup', '--json', $json], $cwd);

        $this->assertSame(0, $code, 'a relative root must run through the launcher; stderr was: ' . $err);
        $this->assertTrue(is_file($json));
        $report = json_decode((string) file_get_contents($json), true);
        $this->assertIsArray($report);

        // Discovery sanity — NOT the discriminator (counts are identical on
        // both sides of the mutant; r1 §(b)).
        $this->assertGreaterThanOrEqual(1, (int) $report['summary']['clusters']);

        $paths = $report['config']['paths'] ?? null;
        $this->assertIsArray($paths);
        $this->assertSame(
            [$anchor . '/dup'],
            $paths,
            'the argv root must reach the report absolutized on the invocation CWD — a relative spelling here means the execute() boundary call was dropped'
        );

        $this->assertArrayHasKey('clusters', $report);
        foreach ($report['clusters'] as $cluster) {
            foreach ($cluster['members'] as $member) {
                $this->assertStringStartsWith(
                    $anchor . '/dup/',
                    (string) ($member['file'] ?? ''),
                    'member paths must grow from the absolutized root, never from the typed relative one'
                );
            }
        }
    }

    public function testBareDryRunFlagBeforeAPathStillScansItThroughTheRealLauncher(): void
    {
        // The VALUE_OPTIONAL registration has the token-swallow hazard
        // (ArgvInput would bind "src" as --dry-run's value and analyze would
        // die at the path door). The run() normalizer is the only cure; a
        // subprocess through the REAL launcher is the only honest proof.
        $tree = $this->makeS19DupTree();
        $cwd = $this->makeS19ScratchDir();
        $json = $cwd . '/bare-dry-run.json';

        [$code, , $err] = $this->runPhpDup(['--dry-run', $tree, '--json', $json], $cwd);

        $this->assertSame(0, $code, '--dry-run must not swallow the path argument; stderr was: ' . $err);
        $this->assertTrue(is_file($json));
        $report = json_decode((string) file_get_contents($json), true);
        $this->assertIsArray($report);
        $this->assertGreaterThanOrEqual(1, (int) $report['summary']['clusters']);
    }

    public function testApplyDryRunPosturesSwitchTheArtifactEndToEnd(): void
    {
        // shell-039 acceptance with real behavior, through the real
        // launcher: preview posture lands refactored/apply.diff and leaves
        // sources untouched; --dry-run=false lands refactored/apply-summary.md
        // instead (the F1b branch that used to be unreachable). The
        // artifact-name switch is the observable — whether F1b rewrites a
        // given pair is the apply extractor's per-cluster safety business,
        // not this flag's contract.
        $prev = $this->makeS19DupTree();
        $real = $this->makeS19DupTree();

        [$codeA] = $this->runPhpDup(['--apply', $prev, '--json', $prev . '/a.json'], $prev);
        $this->assertSame(0, $codeA);
        $this->assertTrue(is_file($prev . '/refactored/apply.diff'), 'default posture must preview via apply.diff');
        $this->assertFalse(is_file($prev . '/refactored/apply-summary.md'), 'preview posture must not run F1b');

        [$codeB] = $this->runPhpDup(['--apply', '--dry-run=false', $real, '--json', $real . '/b.json'], $real);
        $this->assertSame(0, $codeB);
        $this->assertTrue(is_file($real . '/refactored/apply-summary.md'), '--dry-run=false must land the F1b summary artifact');
        $this->assertFalse(is_file($real . '/refactored/apply.diff'), 'real-apply posture must not emit the preview diff');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function runtimeReportArgs(array $params): array
    {
        $parsed = $this->parseRuntime($params);
        $this->assertIsArray($parsed, 'the input must be accepted by the runtime door');

        return $parsed['reportArgs'];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function parseRuntime(array $params): ?array
    {
        $command = new Command();
        $input = new ArrayInput($params, $command->getDefinition());

        $method = new \ReflectionMethod(Command::class, 'parseRuntimeOptions');

        /** @var array<string,mixed>|null $parsed */
        $parsed = $method->invoke($command, $input, new NullOutput(), false);
        return $parsed;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function absolutize(array $paths): array
    {
        $command = new Command();
        $method = new \ReflectionMethod(Command::class, 'absoluteArgvScanRoots');

        /** @var list<string> $roots */
        $roots = $method->invoke($command, $paths);
        return $roots;
    }

    protected function tearDown(): void
    {
        foreach ($this->s19ScratchDirs as $dir) {
            $this->deleteS19Tree($dir);
        }
        $this->s19ScratchDirs = [];

        parent::tearDown();
    }

    /** @return list<string> */
    private function runPhpDup(array $args, string $cwd): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open([PHP_BINARY, __DIR__ . '/../../../bin/phpdup', ...$args], $descriptors, $pipes, $cwd);
        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, $stdout, $stderr];
    }

    private function makeS19DupTree(): string
    {
        $root = $this->makeS19ScratchDir();
        mkdir($root . '/src', 0o777, true);
        foreach (['AlphaDup', 'BetaDup'] as $class) {
            file_put_contents($root . '/src/' . $class . '.php', str_replace('%CLASS%', $class, self::DUP_CLASS));
        }

        return $root;
    }

    private function makeS19ScratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-s19-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail('cannot create scratch dir ' . $dir);
        }
        $this->s19ScratchDirs[] = $dir;

        return $dir;
    }

    private function deleteS19Tree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
