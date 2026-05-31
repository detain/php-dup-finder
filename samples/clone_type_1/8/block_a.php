<?php
declare(strict_types=1);

namespace Acme\Jobs\Email;

final class EmailDeliveryJob
{
    /**
     * Run a callable with retry and exponential backoff.
     *
     * @param callable():bool $task     unit of work returning success
     * @param int             $maxTries upper bound on attempts
     */
    public function runWithRetry(callable $task, int $maxTries = 5): bool
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

    public function handle(string $to): void
    {
        // build mailer and call runWithRetry()
    }
}
