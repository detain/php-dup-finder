<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Command\RebuildSearchIndex;
use App\Service\SearchIndexer;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RebuildSearchIndexTest extends TestCase
{
    use ProphecyTrait;

    public function testRunsAndReportsCount(): void
    {
        $indexer = $this->prophesize(SearchIndexer::class);
        $indexer->rebuildAll(Argument::type('string'))
            ->shouldBeCalledOnce()
            ->willReturn(4321);

        $command = new RebuildSearchIndex($indexer->reveal());
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute([
            '--index' => 'products',
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Indexed 4321 records', $tester->getDisplay());
    }

    public function testWithoutForceSkipsRebuild(): void
    {
        $indexer = $this->prophesize(SearchIndexer::class);
        $indexer->rebuildAll(Argument::any())->shouldNotBeCalled();

        $command = new RebuildSearchIndex($indexer->reveal());
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute(['--index' => 'products']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Pass --force', $tester->getDisplay());
    }
}
