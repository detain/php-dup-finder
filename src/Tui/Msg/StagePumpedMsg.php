<?php
declare(strict_types=1);

namespace Phpdup\Tui\Msg;

use SugarCraft\Core\Msg;

/**
 * Fired by the SugarCraft runtime once per scheduled tick to pump the
 * cooperative {@see \Phpdup\Pipeline\Pipeline::iter()} generator one step.
 *
 * Each handler call advances the pipeline to its next yield point — one
 * pre/post-stage frame, or one mid-stage progress checkpoint. The model
 * then schedules another StagePumpedMsg unless the generator is exhausted.
 */
final class StagePumpedMsg implements Msg {}
