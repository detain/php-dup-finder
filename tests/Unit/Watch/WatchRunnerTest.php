<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Watch;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Watch\WatchRunner;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ui-grammar: the two watch-mode render surfaces (the "watching …" line and
 * the candidate-budget halt posture) are pinned through the seams extracted
 * from run(); the blocking event loop itself stays unexercised.
 */
final class WatchRunnerTest extends TestCase
{
    public function testAnnounceWatchingSingularizesSingleFile(): void
    {
        $output = new BufferedOutput();
        $this->runner($output)->announceWatching(1);

        $rendered = $output->fetch();
        $this->assertStringContainsString('watching 1 file · last update', $rendered);
        $this->assertStringNotContainsString('1 files', $rendered);
    }

    public function testAnnounceWatchingStaysPluralAboveOne(): void
    {
        $output = new BufferedOutput();
        $this->runner($output)->announceWatching(2);

        $this->assertStringContainsString('watching 2 files · last update', $output->fetch());
    }

    /**
     * A budget halt on the watch path renders as the clean `phpdup:` line
     * (mirror of the ft-n2 plain-path catch in Command::execute()) — never
     * Symfony's throwable renderer with its usage dump or a stack trace.
     */
    public function testBudgetHaltKeepsWatchSurfaceClean(): void
    {
        $output = new BufferedOutput();
        $pipeline = new Pipeline([new class implements StageInterface {
            public function name(): Stage
            {
                return Stage::Clustering;
            }

            public function run(PipelineState $state, OutputInterface $output): void
            {
                throw CandidateBudgetException::exceeded(1, 1, 2);
            }
        }]);

        $runner = new WatchRunner(
            $pipeline,
            static fn(): PipelineState => new PipelineState(Config::defaults([sys_get_temp_dir()])),
            $output,
            new NullLogger(),
            sys_get_temp_dir(),
        );
        $runner->runGuarded(new PipelineState(Config::defaults([sys_get_temp_dir()])));

        $rendered = $output->fetch();
        $this->assertStringContainsString('phpdup: Candidate generation exceeded', $rendered);
        $this->assertStringNotContainsString('Usage:', $rendered);
        $this->assertStringNotContainsString('Stack trace:', $rendered);
    }

    private function runner(BufferedOutput $output): WatchRunner
    {
        return new WatchRunner(
            new Pipeline([]),
            static fn(): PipelineState => new PipelineState(Config::defaults([sys_get_temp_dir()])),
            $output,
            new NullLogger(),
            sys_get_temp_dir(),
        );
    }
}
