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
 * Yield accounting should follow COST, not raw item counts (once-S72 /
 * php-dup-finder-once-087): units of wildly different work (one scanned
 * path vs one artifact writer over thousands of clusters) share no honest
 * constant count, and count-based rhythms mis-tune both ways. The
 * ReportStage idiom is a wall-clock budget re-anchored AFTER each yield —
 * driver time between pumps is never charged to the stage, so fast phases
 * yield rarely and slow phases yield often. Count-capped stages (the
 * YIELD_EVERY family) keep their contracts; cost budgeting is the newer
 * tool for phases whose per-unit cost spans orders of magnitude.
 *
 * Soft-cancel contract: a cooperative stage consults
 * {@see PipelineState::$cancelled} between yields and breaks out cleanly
 * for a partial report — with the ONE sanctioned exception of ReportStage
 * itself: producing that partial report IS its job, so it runs (and keeps
 * yielding) whatever the cancel flag says. Pipeline::iter() codifies the
 * same exemption by skipping every other stage once cancelled.
 *
 * Stages that don't implement this interface are still supported by the
 * pipeline; they simply run to completion in one synchronous chunk.
 */
interface CooperativeStageInterface extends StageInterface
{
    /** @return \Generator<int, Stage> */
    public function iter(PipelineState $state, OutputInterface $output): \Generator;
}
