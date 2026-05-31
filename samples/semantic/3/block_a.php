<?php

declare(strict_types=1);

namespace Acme\Billing\Renewal;

use Acme\Billing\Model\Subscription;
use Acme\Billing\Repository\SubscriptionRepository;
use Acme\Billing\Mailer\RenewalMailer;
use DateTimeImmutable;

final class RenewalScheduler
{
    private const GRACE_DAYS = 3;

    public function __construct(
        private SubscriptionRepository $subs,
        private RenewalMailer $mailer,
    ) {
    }

    public function scheduleReminders(): int
    {
        $sent = 0;
        $now = new DateTimeImmutable();

        foreach ($this->subs->dueSoon() as $sub) {
            $status = $sub->status();
            $endsAt = $sub->periodEndsAt()->modify('+' . self::GRACE_DAYS . ' days');

            $isActive = in_array($status, ['active', 'grace'], true) && $endsAt > $now;
            if (!$isActive) {
                continue;
            }

            $this->mailer->sendRenewalReminder($sub);
            $sent++;
        }

        return $sent;
    }
}
