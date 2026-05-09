<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Symfony\Component\Console\Command\CompleteCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dumps a shell completion script for phpdup, prefixed with commented-out
 * installation instructions tailored to the chosen shell.
 *
 * Replaces Symfony Console's built-in `completion` command so contributors get
 * step-by-step setup notes inline with the script:
 *
 *     phpdup completion bash >  ~/.local/share/bash-completion/completions/phpdup
 *     phpdup completion fish >  ~/.config/fish/completions/phpdup.fish
 *     phpdup completion zsh  >  ~/.zsh/completions/_phpdup
 *
 * The script body itself comes from symfony/console's Resources/completion.<shell>
 * file (with the standard {{ COMMAND_NAME }} / {{ VERSION }} substitutions), so
 * the contract with the rest of Symfony's Console autocompletion machinery is
 * preserved exactly — only the human-facing instructions change.
 */
final class CompletionCommand extends SymfonyCommand
{
    /** @var list<string> */
    private const SUPPORTED_SHELLS = ['bash', 'fish', 'zsh'];

    protected function configure(): void
    {
        $this->setName('completion')
            ->setDescription('Dump a shell completion script for phpdup')
            ->setHelp(
                "Dump a shell completion script tailored to your shell.\n\n" .
                "Output starts with commented-out installation instructions; pipe to a file\n" .
                "or the appropriate completion-loader path. Supported: bash, fish, zsh."
            )
            ->addArgument(
                'shell',
                InputArgument::OPTIONAL,
                'Target shell (bash|fish|zsh). When omitted, $SHELL is consulted.',
                null,
                self::SUPPORTED_SHELLS,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawShell = $input->getArgument('shell');
        $shell    = is_string($rawShell) && $rawShell !== '' ? $rawShell : self::guessShell();
        if (!in_array($shell, self::SUPPORTED_SHELLS, true)) {
            $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            if ($shell === '') {
                $err->writeln(sprintf(
                    '<error>phpdup: shell not detected. Pass one of: %s.</error>',
                    implode(', ', self::SUPPORTED_SHELLS),
                ));
            } else {
                $err->writeln(sprintf(
                    '<error>phpdup: shell "%s" not supported. Use one of: %s.</error>',
                    $shell, implode(', ', self::SUPPORTED_SHELLS),
                ));
            }
            return 2;
        }

        $resourcesDir = self::resourcesDir();
        $resource = $resourcesDir . '/completion.' . $shell;
        if (!is_file($resource)) {
            $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $err->writeln("<error>phpdup: completion script for {$shell} not found at {$resource}.</error>");
            return 1;
        }

        $commandName = $this->guessCommandName();
        $body = (string)file_get_contents($resource);
        $body = str_replace(
            ['{{ COMMAND_NAME }}', '{{ VERSION }}'],
            [$commandName, CompleteCommand::COMPLETION_API_VERSION],
            $body,
        );

        $header = self::installInstructions($shell, $commandName);

        // Zsh requires `#compdef <command>` to be the very first line; insert our
        // commented header *after* that directive so autoload still sees it.
        if ($shell === 'zsh' && str_starts_with($body, '#compdef ')) {
            [$first, $rest] = self::splitFirstLine($body);
            $output->write($first . "\n" . $header . $rest);
        } else {
            $output->write($header . $body);
        }

        return 0;
    }

    /**
     * Produce the commented-out setup-instructions block for the given shell.
     * Every line is a `#` comment — paste into the script untouched.
     */
    private static function installInstructions(string $shell, string $commandName): string
    {
        $heading = "# phpdup shell-completion script for $shell\n#\n";
        $sharedFooter =
            "#\n" .
            "# Re-generate any time CLI flags change:\n" .
            "#\n" .
            "#     $commandName completion $shell > <path-from-above>\n" .
            "#\n" .
            "# Verify it loaded:  type $commandName  (then press Tab on the next line)\n" .
            "#\n";

        return match ($shell) {
            'bash' => $heading .
                "# Install (any one of these):\n" .
                "#\n" .
                "#   1. Per-user completion (preferred — survives shell upgrades):\n" .
                "#\n" .
                "#       mkdir -p ~/.local/share/bash-completion/completions\n" .
                "#       $commandName completion bash > ~/.local/share/bash-completion/completions/$commandName\n" .
                "#\n" .
                "#   2. System-wide:\n" .
                "#\n" .
                "#       $commandName completion bash | sudo tee /etc/bash_completion.d/$commandName\n" .
                "#\n" .
                "#   3. Inline in your ~/.bashrc:\n" .
                "#\n" .
                "#       eval \"\$($commandName completion bash)\"\n" .
                "#\n" .
                "# Then start a new shell, or 'source' the file. Requires the bash-completion\n" .
                "# package (most distros install it by default).\n" .
                $sharedFooter,
            'fish' => $heading .
                "# Install:\n" .
                "#\n" .
                "#   1. Per-user (auto-loaded on next shell start):\n" .
                "#\n" .
                "#       mkdir -p ~/.config/fish/completions\n" .
                "#       $commandName completion fish > ~/.config/fish/completions/$commandName.fish\n" .
                "#\n" .
                "#   2. System-wide (Linux):\n" .
                "#\n" .
                "#       $commandName completion fish | sudo tee /etc/fish/completions/$commandName.fish\n" .
                "#\n" .
                "#   3. Inline in ~/.config/fish/config.fish:\n" .
                "#\n" .
                "#       $commandName completion fish | source\n" .
                "#\n" .
                $sharedFooter,
            'zsh' => $heading .
                "# Install:\n" .
                "#\n" .
                "#   1. Per-user — pick a directory on your \$fpath (e.g. ~/.zsh/completions),\n" .
                "#      add it to fpath BEFORE compinit, then dump the script:\n" .
                "#\n" .
                "#       mkdir -p ~/.zsh/completions\n" .
                "#       $commandName completion zsh > ~/.zsh/completions/_$commandName\n" .
                "#\n" .
                "#       # Add to ~/.zshrc, before 'compinit':\n" .
                "#       fpath=(~/.zsh/completions \$fpath)\n" .
                "#       autoload -Uz compinit && compinit\n" .
                "#\n" .
                "#   2. System-wide:\n" .
                "#\n" .
                "#       $commandName completion zsh | sudo tee /usr/local/share/zsh/site-functions/_$commandName\n" .
                "#\n" .
                "#   3. Inline in ~/.zshrc:\n" .
                "#\n" .
                "#       eval \"\$($commandName completion zsh)\"\n" .
                "#\n" .
                "# Filename matters: zsh's autoload looks for files named _<command>.\n" .
                $sharedFooter,
            default => $heading,
        };
    }

    /**
     * Locate symfony/console's Resources directory, which ships the actual
     * shell-completion scripts. Falls back to a search relative to vendor/ to
     * be robust across composer-install layouts.
     */
    private static function resourcesDir(): string
    {
        $direct = __DIR__ . '/../../vendor/symfony/console/Resources';
        if (is_dir($direct)) {
            return $direct;
        }
        // Fallback: walk up looking for a vendor/symfony/console/Resources dir.
        $cursor = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $candidate = $cursor . '/vendor/symfony/console/Resources';
            if (is_dir($candidate)) {
                return $candidate;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) break;
            $cursor = $parent;
        }
        // Best-effort default — tests will surface the failure clearly.
        return $direct;
    }

    private function guessCommandName(): string
    {
        // Prefer the application name when registered — that's the canonical
        // binary name (e.g. 'phpdup'), regardless of what argv[0] happens to
        // be (which under `vendor/bin/phpunit` would point at PHPUnit itself).
        $app = $this->getApplication();
        if ($app !== null) {
            $name = $app->getName();
            if ($name !== '' && $name !== 'UNKNOWN') {
                return $name;
            }
        }
        $argv0 = $_SERVER['argv'][0] ?? 'phpdup';
        return basename((string)$argv0);
    }

    private static function guessShell(): string
    {
        return basename((string)($_SERVER['SHELL'] ?? ''));
    }

    /** @return array{0:string,1:string} */
    private static function splitFirstLine(string $body): array
    {
        $nl = strpos($body, "\n");
        if ($nl === false) {
            return [$body, ''];
        }
        return [substr($body, 0, $nl), substr($body, $nl + 1)];
    }
}
