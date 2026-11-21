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
}
