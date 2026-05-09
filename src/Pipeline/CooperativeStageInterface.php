<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stages that can be paused mid-execution to let the renderer repaint.
 *
 * Cooperative stages return a {@see \Generator} that yields after each unit of
 * progress (one file scanned, one preprocess result back from a worker, …).
 * The driver (synchronous {@see Pipeline::run()} or live {@see Pipeline::iter()})
 * is responsible for advancing the generator and choosing when to repaint.
 *
 * Each yielded value is the {@see Stage} the work belongs to — useful when
 * upstream code multiplexes generators from several stages, or wants a quick
 * sanity check on which stage is currently advancing.
 *
 * Stages that don't implement this interface are still supported by the
 * pipeline; they simply run to completion in one synchronous chunk.
 */
interface CooperativeStageInterface extends StageInterface
{
    /** @return \Generator<int, Stage> */
    public function iter(PipelineState $state, OutputInterface $output): \Generator;
}
