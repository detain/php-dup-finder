<?php
declare(strict_types=1);

namespace Acme\Time;

final class LocalizedTimestamp
{
    public function __construct(
        public readonly string $iso,
        public readonly string $human,
        public readonly int $epoch,
        public readonly string $offsetLabel,
    ) {
    }
}

final class TimezoneFormatter
{
    public function localize(\DateTimeImmutable $utc, ?string $tzName): LocalizedTimestamp
    {
        try {
            $targetTz = new \DateTimeZone($tzName ?: 'UTC');
        } catch (\Exception) {
            $targetTz = new \DateTimeZone('UTC');
        }
        $local = $utc->setTimezone($targetTz);
        $offsetSeconds = $targetTz->getOffset($local);
        $offsetLabel = sprintf('%+03d:%02d', intdiv($offsetSeconds, 3600), abs($offsetSeconds % 3600) / 60);

        return new LocalizedTimestamp(
            iso: $local->format(\DateTimeInterface::ATOM),
            human: $local->format('M j, Y \a\t g:i A T'),
            epoch: $local->getTimestamp(),
            offsetLabel: $offsetLabel,
        );
    }
}

final class AuditLogFormatter
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TimezoneFormatter $tz,
    ) {
    }

    public function format(int $userId, \DateTimeImmutable $occurredAtUtc, string $action): string
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new \RuntimeException("User {$userId} not found");
        }
        $t = $this->tz->localize($occurredAtUtc, $user->timezone());
        return sprintf('[%s] %s by user %d (epoch=%d offset=%s)', $t->iso, $action, $userId, $t->epoch, $t->offsetLabel);
    }
}
