<?php
declare(strict_types=1);

namespace Fitness\Notify;

final class ThankYouCopy
{
    public const TEMPLATE = "Thank you for staying consistent with PulseFit! We noticed you completed your "
        . "{ACTION_SUMMARY} session. Keep tapping the badge icon to log streaks. "
        . "If anything feels off, our coaches answer in under an hour at help@pulsefit.example.com. "
        . "\n\nPS: every small win counts — your future self is cheering you on.";

    public static function render(string $actionSummary): string
    {
        return str_replace('{ACTION_SUMMARY}', $actionSummary, self::TEMPLATE);
    }
}

abstract class BasePushSender
{
    public function __construct(protected Apns $apns) {}

    protected function pushThankYou(string $deviceToken, string $title, string $actionSummary): void
    {
        $this->apns->push(
            token: $deviceToken,
            payload: [
                'aps' => [
                    'alert' => ['title' => $title, 'body' => ThankYouCopy::render($actionSummary)],
                    'sound' => 'default',
                    'badge' => 1,
                ],
            ],
        );
    }
}

final class WorkoutPushSender extends BasePushSender
{
    public function dispatch(string $deviceToken, string $workoutTitle): void
    {
        $this->pushThankYou($deviceToken, 'Workout complete', $workoutTitle);
    }
}

final class MealLogPushSender extends BasePushSender
{
    public function dispatch(string $deviceToken, int $caloriesLogged): void
    {
        $this->pushThankYou($deviceToken, 'Meal logged', "meal log ({$caloriesLogged} kcal)");
    }
}
