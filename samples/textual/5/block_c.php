<?php
declare(strict_types=1);

namespace Fitness\Notify;

final class SleepLogPushSender
{
    public function __construct(private \Fitness\Notify\Apns $apns) {}

    public function dispatch(string $deviceToken, float $hoursSlept): void
    {
        $body = "Thank you for staying consistent with PulseFit! We noticed you completed your "
              . sprintf('sleep log (%0.1fh) session.', $hoursSlept) . " Keep tapping the badge icon to log streaks. "
              . "If anything feels off, our coaches answer in under an hour at help@pulsefit.example.com. "
              . "\n\nPS: every small win counts — your future self is cheering you on.";

        $this->apns->push(
            token: $deviceToken,
            payload: [
                'aps' => [
                    'alert' => [
                        'title' => 'Sleep logged',
                        'body' => $body,
                    ],
                    'sound' => 'default',
                    'badge' => 1,
                ],
            ],
        );
    }
}
