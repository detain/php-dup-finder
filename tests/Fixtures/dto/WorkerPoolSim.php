<?php
declare(strict_types=1);

namespace Phpdup\Fixtures\Dto;

class WorkerPoolSim
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
