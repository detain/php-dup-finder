<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Command\CleanupExpiredTokens;
use App\Service\TokenStore;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupExpiredTokensTest extends TestCase
{
    use ProphecyTrait;

    public function testRunsAndReportsCount(): void
    {
        $tokens = $this->prophesize(TokenStore::class);
        $tokens->purgeExpiredOlderThan(Argument::type('DateTimeImmutable'))
            ->shouldBeCalledOnce()
            ->willReturn(17);

        $command = new CleanupExpiredTokens($tokens->reveal());
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute([
            '--older-than' => '7 days',
            '--dry-run'    => false,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Purged 17 expired tokens', $tester->getDisplay());
    }

    public function testDryRunSkipsPurge(): void
    {
        $tokens = $this->prophesize(TokenStore::class);
        $tokens->purgeExpiredOlderThan(Argument::any())->shouldNotBeCalled();

        $command = new CleanupExpiredTokens($tokens->reveal());
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute(['--older-than' => '7 days', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Dry-run', $tester->getDisplay());
    }
}
