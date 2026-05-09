<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\CompletionCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CompletionCommandTest extends TestCase
{
    public function testBashOutputStartsWithInstallInstructionsAndContainsScriptBody(): void
    {
        $output = $this->dump(['shell' => 'bash']);

        $this->assertStringStartsWith('# phpdup shell-completion script for bash', $output);
        $this->assertStringContainsString('# Install', $output);
        $this->assertStringContainsString('eval "$(phpdup completion bash)"', $output);
        // Script body is the symfony resource — look for its trademark function.
        $this->assertStringContainsString('_sf_phpdup', $output);
        $this->assertStringContainsString('complete -F _sf_phpdup phpdup', $output);
    }

    public function testFishOutputContainsFishSpecificInstructions(): void
    {
        $output = $this->dump(['shell' => 'fish']);

        $this->assertStringStartsWith('# phpdup shell-completion script for fish', $output);
        $this->assertStringContainsString('~/.config/fish/completions/phpdup.fish', $output);
        // Fish completion uses `complete -c <cmd>` (typically with a quoted name);
        // verify the body wasn't lost.
        $this->assertMatchesRegularExpression("/\\bcomplete\\s+-c\\s+'?phpdup'?\\b/", $output);
    }

    public function testZshFirstLineIsCompdefDirective(): void
    {
        $output = $this->dump(['shell' => 'zsh']);

        $firstLine = strtok($output, "\n");
        $this->assertSame(
            '#compdef phpdup',
            $firstLine,
            'zsh autoload requires #compdef as the very first line — install instructions must come AFTER it',
        );
    }

    public function testZshOutputIncludesInstructionsAfterCompdef(): void
    {
        $output = $this->dump(['shell' => 'zsh']);

        $this->assertStringContainsString('# phpdup shell-completion script for zsh', $output);
        $this->assertStringContainsString('~/.zsh/completions/_phpdup', $output);
        $this->assertStringContainsString('fpath=(~/.zsh/completions $fpath)', $output);
        // Verify the instructions appear *after* the compdef line.
        $compdefPos = strpos($output, '#compdef phpdup');
        $headerPos  = strpos($output, '# phpdup shell-completion script for zsh');
        $this->assertNotFalse($compdefPos);
        $this->assertNotFalse($headerPos);
        $this->assertLessThan($headerPos, $compdefPos);
    }

    public function testUnknownShellExitsWithCodeTwoAndUsefulMessage(): void
    {
        $tester = $this->commandTester();
        $exit = $tester->execute(['shell' => 'ksh'], ['capture_stderr_separately' => true]);

        $this->assertSame(2, $exit);
        $combined = $tester->getErrorOutput() . $tester->getDisplay();
        $this->assertStringContainsString('not supported', $combined);
        $this->assertStringContainsString('bash, fish, zsh', $combined);
    }

    public function testHelpDescribesSupportedShells(): void
    {
        $cmd = new CompletionCommand();
        $help = $cmd->getHelp();
        $this->assertStringContainsString('bash, fish, zsh', $help);
        $this->assertStringContainsString('installation instructions', $help);
    }

    public function testInstructionsAreEntirelyCommentLines(): void
    {
        // The prepended header must consist only of comment lines and blanks
        // so the shell doesn't try to execute it when sourcing. We walk the
        // script line-by-line until the first non-blank, non-comment line
        // (start of the actual script body) and verify the count of header
        // lines is reasonable for each shell.
        foreach (['bash', 'fish', 'zsh'] as $shell) {
            $output = $this->dump(['shell' => $shell]);
            $headerLineCount = 0;
            foreach (explode("\n", $output) as $line) {
                $trim = trim($line);
                if ($trim === '' || str_starts_with($trim, '#')) {
                    $headerLineCount++;
                    continue;
                }
                break;
            }
            $this->assertGreaterThan(
                5,
                $headerLineCount,
                "$shell: expected several comment lines before the script body (got {$headerLineCount})",
            );
        }
    }

    public function testWiringIntoApplicationOverridesDefaultSymfonyCompletion(): void
    {
        // When we register CompletionCommand on an Application, our wrapper
        // must take precedence over Symfony's auto-registered DumpCompletionCommand.
        $app = new Application('phpdup', '0.1.0');
        $app->add(new CompletionCommand());
        $found = $app->find('completion');
        $this->assertInstanceOf(CompletionCommand::class, $found);
    }

    /** @param array<string,mixed> $args */
    private function dump(array $args): string
    {
        $tester = $this->commandTester();
        $tester->execute($args);
        return $tester->getDisplay();
    }

    private function commandTester(): CommandTester
    {
        $app = new Application('phpdup', '0.1.0');
        $app->add(new CompletionCommand());
        $cmd = $app->find('completion');
        return new CommandTester($cmd);
    }
}
