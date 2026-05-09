<?php
declare(strict_types=1);

namespace Phpdup\Tui\Msg;

use SugarCraft\Core\Msg;

/**
 * Sent by {@see \Phpdup\Watch\WatchRunner} (or any other observer) to ask
 * {@see \Phpdup\Tui\PhpdupModel} to discard the current PipelineState, build
 * a fresh one, and restart the cooperative {@see \Phpdup\Pipeline\Pipeline::iter()}
 * loop. Used when watch-mode detects a file change while the TUI is up.
 */
final class RestartPipelineMsg implements Msg
{
    public function __construct(
        /** @var int Reload counter so the dashboard can show "reload #N". */
        public readonly int $reload,
    ) {}
}
