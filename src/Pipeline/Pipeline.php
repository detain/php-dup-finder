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
    /** @param list<StageInterface> $stages */
    public function __construct(
        private readonly array $stages,
        private readonly ?Stage $stopAfter = null,
    ) {}

    public function run(PipelineState $state, OutputInterface $output): PipelineState
    {
        foreach ($this->stages as $stage) {
            $state->stage = $stage->name();
            $state->stageProgress = 0.0;
            $stage->run($state, $output);
            $state->stageProgress = 1.0;

            if ($this->stopAfter !== null && $stage->name() === $this->stopAfter) {
                break;
            }
        }
        return $state;
    }
}
