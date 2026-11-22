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
                return [$h, $g, $f, $e, $d, $c, $b, $a];
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
