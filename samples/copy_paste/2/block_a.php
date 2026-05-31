<?php
declare(strict_types=1);

namespace Acme\Audit;

final class AuditLogFormatter
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function format(int $userId, \DateTimeImmutable $occurredAtUtc, string $action): string
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new \RuntimeException("User {$userId} not found");
        }

        // ---- BEGIN copy-pasted timezone conversion ----
        $tzName = $user->timezone() ?: 'UTC';
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

        return sprintf('[%s] %s by user %d (epoch=%d offset=%s)', $iso, $action, $userId, $epoch, $offsetLabel);
    }
}
