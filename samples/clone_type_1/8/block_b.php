<?php
declare(strict_types=1);

namespace Acme\Jobs\Webhook;

final class WebhookDispatchJob
{
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
      // sleep then double the delay
      usleep($delay);
      $delay = $delay * 2;
      // cap to five seconds
      if ($delay > 5000000) {
        $delay = 5000000;
      }
    }
    return false;
  }

  public function handle(string $url): void
  {
    // build curl client and call runWithRetry()
  }
}
