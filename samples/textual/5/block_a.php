<?php
declare(strict_types=1);

namespace Fitness\Notify;

final class WorkoutPushSender
{
    public function __construct(private \Fitness\Notify\Apns $apns) {}

    public function dispatch(string $deviceToken, string $workoutTitle): void
    {
        $body = "Thank you for staying consistent with PulseFit! We noticed you completed your "
              . $workoutTitle . " session. Keep tapping the badge icon to log streaks. "
              . "If anything feels off, our coaches answer in under an hour at help@pulsefit.example.com. "
              . "\n\nPS: every small win counts — your future self is cheering you on.";

        $this->apns->push(
            token: $deviceToken,
            payload: [
                'aps' => [
                    'alert' => [
                        'title' => 'Workout complete',
                        'body' => $body,
                    ],
                    'sound' => 'default',
                    'badge' => 1,
                ],
            ],
        );
    }
}
