<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Command\ArchiveOldLogs;
use App\Service\LogArchiver;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ArchiveOldLogsTest extends TestCase
{
    use ProphecyTrait;

    public function testRunsAndReportsCount(): void
    {
        $archiver = $this->prophesize(LogArchiver::class);
        $archiver->archiveBefore(Argument::type('DateTimeImmutable'))
            ->shouldBeCalledOnce()
            ->willReturn(902);

        $command = new ArchiveOldLogs($archiver->reveal());
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute([
            '--before'   => '30 days',
            '--compress' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Archived 902 log entries', $tester->getDisplay());
    }

    public function testCompressFalseSkipsArchive(): void
    {
        $archiver = $this->prophesize(LogArchiver::class);
        $archiver->archiveBefore(Argument::any())->shouldNotBeCalled();

        $command = new ArchiveOldLogs($archiver->reveal());
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute(['--before' => '30 days', '--compress' => false]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Compression disabled', $tester->getDisplay());
    }
}
