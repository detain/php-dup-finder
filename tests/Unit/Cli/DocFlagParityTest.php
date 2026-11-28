<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Command;
use Phpdup\Cli\InitCommand;
use Phpdup\Cli\ServeCommand;
use Phpdup\Cli\UpdateCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * once-S18 (php-dup-finder-once-021, HIGH): docs/CLI.md opens by claiming to be a
 * "Standalone reference for every flag and sub-command" — this belt makes that
 * claim mechanical instead of aspirational. Both directions are DERIVED at test
 * time from live code and live markdown; there is deliberately no hand-maintained
 * expected-flag list here (the vacuity trap once-S31/S15 taught us).
 *
 * - live -> docs: every option registered on analyze / serve / init / self:update
 *   must appear as a long token in a docs/CLI.md table-row first cell; merged
 *   Symfony globals must appear as a long token or as one of their shortcut
 *   spellings (the built-ins table has always listed `-v` / `-vv` / `-vvv`).
 * - docs -> live: every flag token in a row first cell must be a registered argv
 *   option — own, global, or the legal negated spelling of a negatable global
 *   (`--no-ansi` on `ansi`), except the single decay-pinned exception below.
 *
 * One exception is unavoidable today: `--ml-pair-allow-private` has a documented
 * row and a live root config key, but its argv registration is deferred to the
 * next Command.php option-roster leg (Config.php notes; once-S20 landed without
 * registering it). test_argv_deferral_has_not_decayed() pins BOTH halves — while
 * the exception stands the token must remain unregistered AND stay documented —
 * so the moment a lane wires the flag the pin flips RED and forces the exception
 * away. An exception can never rot into shelter for a real ghost.
 *
 * Axis boundary: ConfigSchemaParityTest (once-S51 / shell-S31 era) compares
 * docs/config-schema.json against Config.php's config keys. This belt compares
 * the argv flag surface against docs/CLI.md rows. Different artefacts, different
 * question — deliberately not merged into one checker.
 */
final class DocFlagParityTest extends TestCase
{
    /** Rows documented but argv-deferred by design, each decay-pinned below. */
    private const DEFERRED_ARGV_ROWS = ['ml-pair-allow-private'];

    /** @var array<string, class-string<SymfonyCommand>> command label => class */
    private const COMMANDS = [
        'analyze' => Command::class,
        'audit' => \Phpdup\Cli\AuditCommand::class,
        'serve' => ServeCommand::class,
        'init' => InitCommand::class,
        'self:update' => UpdateCommand::class,
    ];

    /** The line-3 claim this test enforces; pinned byte-identical. */
    private const CLAIM_LINE = 'Standalone reference for every flag and sub-command in `bin/phpdup`.';

    /** The paragraph that advertises this belt; pinned present. */
    private const BELT_SENTENCE = 'The flag rows in this file are kept in mechanical parity with the live argv roster by `tests/Unit/Cli/DocFlagParityTest.php`: a registered flag with no row here, or a token here that never registers, fails the test.';

    public function test_every_documented_flag_token_is_a_registered_option(): void
    {
        $known = $this->knownLongTokens();

        $unknown = [];
        foreach ($this->parsedDocRows()['longs'] as $token => $_) {
            if (isset($known[$token]) || in_array($token, self::DEFERRED_ARGV_ROWS, true)) {
                continue;
            }
            $unknown[] = '--' . $token;
        }

        self::assertSame(
            [],
            $unknown,
            'docs/CLI.md carries row token(s) no command registers: ' . implode(', ', $unknown)
                . ' — add the option to the roster or drop the row.'
        );
    }

    public function test_every_documented_shortcut_is_registered(): void
    {
        $allowed = [];
        foreach ($this->allRegisteredOptions() as $option) {
            foreach ($this->shortcutParts($option) as $part) {
                $allowed[$part] = true;
            }
        }

        $unknown = [];
        foreach ($this->parsedDocRows()['shorts'] as $token => $_) {
            if (isset($allowed[$token])) {
                continue;
            }
            $unknown[] = '-' . $token;
        }

        self::assertSame(
            [],
            $unknown,
            'docs/CLI.md carries shortcut token(s) no option registers: ' . implode(', ', $unknown)
        );
    }

    public function test_every_registered_option_has_a_documented_row(): void
    {
        $documented = $this->parsedDocRows()['longs'];
        $documentedShorts = $this->parsedDocRows()['shorts'];

        $missing = [];
        foreach (self::COMMANDS as $label => $class) {
            foreach ((new $class())->getDefinition()->getOptions() as $option) {
                if (isset($documented[$option->getName()])) {
                    continue;
                }
                $missing[] = $label . ' --' . $option->getName();
            }
        }
        foreach ($this->mergedGlobals() as $option) {
            if (isset($documented[$option->getName()])) {
                continue;
            }
            // The built-ins table spells some globals by shortcut only (verbose).
            if (array_intersect($this->shortcutParts($option), array_keys($documentedShorts)) !== []) {
                continue;
            }
            $missing[] = 'global --' . $option->getName();
        }

        self::assertSame(
            [],
            $missing,
            'registered flag(s) missing from docs/CLI.md rows: ' . implode(', ', $missing)
        );
    }

    public function test_option_shortcuts_are_shown_on_their_doc_rows(): void
    {
        $byLong = $this->parsedDocRows()['by_long'];

        $violations = [];
        foreach (self::COMMANDS as $label => $class) {
            foreach ((new $class())->getDefinition()->getOptions() as $option) {
                $shortcuts = $this->shortcutParts($option);
                if ($shortcuts === []) {
                    continue;
                }
                $shown = $byLong[$option->getName()] ?? [];
                foreach ($shortcuts as $part) {
                    if (isset($shown[$part])) {
                        continue;
                    }
                    $violations[] = $label . ' --' . $option->getName() . ' is registered with shortcut -'
                        . $part . ' but no docs/CLI.md row carrying it shows -' . $part;
                }
            }
        }

        self::assertSame([], $violations, 'shortcut drift: ' . implode('; ', $violations));
    }

    public function test_argv_deferral_has_not_decayed(): void
    {
        $documented = $this->parsedDocRows()['longs'];
        $registered = $this->knownLongTokens();

        foreach (self::DEFERRED_ARGV_ROWS as $token) {
            self::assertTrue(
                !isset($registered[$token]),
                '--' . $token . ' is now a registered option: remove it from DEFERRED_ARGV_ROWS'
                    . ' (its doc row finally matches argv).'
            );
            self::assertTrue(
                isset($documented[$token]),
                '--' . $token . ' lost its docs/CLI.md row while still unregistered: the argv-deferral'
                    . ' exception describes a documented row and must be deleted together with it.'
            );
        }
    }

    public function test_cli_md_claim_and_belt_paragraph_are_pinned(): void
    {
        $lines = explode("\n", $this->readDocs());

        self::assertSame(
            self::CLAIM_LINE,
            $lines[2] ?? '',
            'The line-3 scope claim is what this belt enforces; changing its wording is changing'
                . ' the promise every reader trusts.'
        );
        self::assertStringContainsString(
            self::BELT_SENTENCE,
            (string) preg_replace('/\s+/', ' ', implode("\n", $lines)),
            'The belt sentence advertising this test was removed or rewritten.'
        );
    }

    /** @return array{longs: array<string, true>, shorts: array<string, true>, by_long: array<string, array<string, true>>} */
    private function parsedDocRows(): array
    {
        $longs = [];
        $shorts = [];
        $byLong = [];

        foreach (explode("\n", $this->readDocs()) as $line) {
            $cell = $this->firstCell($line);
            if ($cell === null) {
                continue;
            }
            $rowLongs = [];
            $rowShorts = [];
            foreach ($this->flagTokens($cell) as $token) {
                if (str_starts_with($token, '--')) {
                    $name = substr($token, 2);
                    $longs[$name] = true;
                    $rowLongs[$name] = true;
                    continue;
                }
                $shorts[substr($token, 1)] = true;
                $rowShorts[substr($token, 1)] = true;
            }
            foreach (array_keys($rowLongs) as $name) {
                foreach (array_keys($rowShorts) as $part) {
                    $byLong[$name][$part] = true;
                }
            }
        }

        return ['longs' => $longs, 'shorts' => $shorts, 'by_long' => $byLong];
    }

    /** First table cell of a markdown pipe-row, or null when the line is not one. */
    private function firstCell(string $line): ?string
    {
        if (!str_starts_with($line, '|')) {
            return null;
        }
        if (preg_match('/^\|((?:[^|\\\\]|\\\\.)*)\|/', $line, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * Flag tokens inside a cell: dash-prefixed spellings only at word starts, so
     * prose like "mid-segment" in the glob-syntax table never masquerades as `-segment`.
     *
     * @return list<string>
     */
    private function flagTokens(string $cell): array
    {
        $found = preg_match_all('/(?<![\w])-{1,2}[A-Za-z][A-Za-z0-9-]*/', $cell, $m);
        if ($found === false || $found === 0) {
            return [];
        }

        return $m[0];
    }

    /** @return array<string, true> every long token argv would accept right now */
    private function knownLongTokens(): array
    {
        $known = [];
        foreach ($this->allRegisteredOptions() as $option) {
            $known[$option->getName()] = true;
            if ($option->isNegatable()) {
                $known['no-' . $option->getName()] = true;
            }
        }

        return $known;
    }

    /** @return list<InputOption> own options of every reviewed command + merged globals */
    private function allRegisteredOptions(): array
    {
        $options = [];
        foreach (self::COMMANDS as $class) {
            foreach ((new $class())->getDefinition()->getOptions() as $option) {
                $options[] = $option;
            }
        }
        foreach ($this->mergedGlobals() as $option) {
            $options[] = $option;
        }

        return $options;
    }

    /**
     * Application-level options exactly as Symfony merges them into each command
     * at run time (help, silent, quiet, verbose, version, ansi, no-interaction).
     *
     * @return array<string, InputOption>
     */
    private function mergedGlobals(): array
    {
        $probe = new SymfonyCommand('once-s18-parity-probe');
        $application = new Application();
        $application->add($probe);

        $merge = new ReflectionMethod(SymfonyCommand::class, 'mergeApplicationDefinition');
        $merge->invoke($probe);

        return $probe->getDefinition()->getOptions();
    }

    /** @return list<string> shortcut spellings of an option (`v|vv|vvv` -> v, vv, vvv) */
    private function shortcutParts(InputOption $option): array
    {
        if ($option->getShortcut() === null || $option->getShortcut() === '') {
            return [];
        }

        return explode('|', $option->getShortcut());
    }

    private function readDocs(): string
    {
        $path = dirname(__DIR__, 3) . '/docs/CLI.md';
        $docs = file_get_contents($path);
        if ($docs === false) {
            throw new RuntimeException('cannot read ' . $path);
        }

        return $docs;
    }
}
