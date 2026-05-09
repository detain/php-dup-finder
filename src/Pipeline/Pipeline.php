<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs registered stages in order against a single PipelineState.
 *
 * Each stage advances $state->stage to its own value before doing work, so the TUI
 * (and tests) can observe the live stage. When $stopAfter is set the pipeline halts
 * after that stage completes — used by --stage to debug an incremental run.
 */
final class Pipeline
{
    private readonly ProgressListener $listener;

    /** @param list<StageInterface> $stages */
    public function __construct(
        private readonly array $stages,
        private readonly ?Stage $stopAfter = null,
        ?ProgressListener $listener = null,
    ) {
        $this->listener = $listener ?? new NullProgressListener();
    }

    public function run(PipelineState $state, OutputInterface $output): PipelineState
    {
        // Drain the cooperative iterator without yielding to anyone.
        foreach ($this->iter($state, $output) as $_) {
            // intentionally empty — synchronous mode discards the cooperative ticks.
        }
        return $state;
    }

    /**
     * Cooperative iteration. Yields a {@see Stage} value at each pause point —
     * once when a stage starts (before any work), additional times mid-stage if
     * the stage implements {@see CooperativeStageInterface}, and once when the
     * stage finishes. Drivers (TUI, watcher) advance the generator when they
     * want to repaint and inspect $state for live counts.
     *
     * @return \Generator<int, Stage>
     */
    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        foreach ($this->stages as $stage) {
            $state->stage         = $stage->name();
            $state->stageProgress = 0.0;
            $this->listener->onStageStart($stage->name());

            // Pre-stage frame so the renderer can show "Stage X starting…".
            yield $stage->name();

            if ($stage instanceof CooperativeStageInterface) {
                yield from $stage->iter($state, $output);
            } else {
                $stage->run($state, $output);
            }

            $state->stageProgress = 1.0;
            $this->listener->onStageEnd($stage->name());

            // Post-stage frame so the renderer can show final counts before the
            // next stage starts overwriting them.
            yield $stage->name();

            if ($this->stopAfter !== null && $stage->name() === $this->stopAfter) {
                break;
            }
        }
    }
}
