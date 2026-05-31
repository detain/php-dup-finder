<?php
declare(strict_types=1);

namespace Acme\Notifications;

final class EmailNotificationRenderer
{
    public function render(Notification $notification, User $recipient): string
    {
        $body = "Hello {$recipient->name()},\n\n";
        $body .= $notification->message() . "\n\n";

        $occurredAtUtc = $notification->scheduledFor();

        // ---- BEGIN copy-pasted timezone conversion ----
        $tzName = $recipient->timezone() ?: 'UTC';
        try {
            $targetTz = new \DateTimeZone($tzName);
        } catch (\Exception $e) {
            $targetTz = new \DateTimeZone('UTC');
        }
        $local = $occurredAtUtc->setTimezone($targetTz);
        $iso = $local->format(\DateTimeInterface::ATOM);
        $human = $local->format('M j, Y \a\t g:i A T');
        $epoch = $local->getTimestamp();
        $offsetSeconds = $targetTz->getOffset($local);
        $offsetLabel = sprintf('%+03d:%02d', intdiv($offsetSeconds, 3600), abs($offsetSeconds % 3600) / 60);
        // ---- END copy-pasted timezone conversion ----

        $body .= "Scheduled for: {$human}\n";
        $body .= "Reference (ISO): {$iso} ({$offsetLabel})\n";
        return $body;
    }
}
