<?php
declare(strict_types=1);

namespace Phpdup\Fixtures\Dto;

/**
 * The "coroutine-predicate triplicate" stand-in (improve_logic.md:323
 * done-when names it explicitly): the same suspension predicate written
 * three times in three unrelated services. Real duplicated logic —
 * mixed statement kinds, several distinct callees — so no demotion leg
 * fires and the doc demands it leads --sort=score.
 */
class Scheduler
{
    public function isSuspendable($task): bool
    {
        if ($task === null) {
            return false;
        }
        if (!$task instanceof \Closure) {
            return $this->registry->hasFlag($task, 'suspend');
        }
        $meta = $this->introspector->meta($task);
        if ($meta === null) {
            return false;
        }
        return $meta->yielded && !$meta->finished;
    }
}
