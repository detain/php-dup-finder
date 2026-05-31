<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

abstract class AbstractConsoleCommandTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @param array<string, mixed> $input
     */
    protected function assertCommandSucceeds(
        Command $command,
        array $input,
        string $expectedOutputFragment
    ): CommandTester {
        $tester   = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString($expectedOutputFragment, $tester->getDisplay());

        return $tester;
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function assertCommandFails(
        Command $command,
        array $input,
        int $expectedExitCode = Command::FAILURE
    ): CommandTester {
        $tester   = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $this->assertSame($expectedExitCode, $exitCode);

        return $tester;
    }
}
