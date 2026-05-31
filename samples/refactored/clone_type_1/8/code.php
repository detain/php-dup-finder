<?php
declare(strict_types=1);

namespace Acme\Jobs\Support;

final class BackoffRunner
{
    /**
     * Run a callable with exponential backoff between failed attempts.
     *
     * @param callable():bool $task
     */
    public static function run(callable $task, int $maxTries = 5): bool
    {
        $attempt = 0;
        $delay = 100000;
        while ($attempt < $maxTries) {
            $attempt++;
            $ok = (bool) $task();
            if ($ok === true) {
                return true;
            }
            if ($attempt >= $maxTries) {
                break;
            }
            usleep($delay);
            $delay = $delay * 2;
            if ($delay > 5000000) {
                $delay = 5000000;
            }
        }
        return false;
    }
}

// Each job now calls BackoffRunner::run($task, $maxTries).
