<?php
declare(strict_types=1);

namespace Acme\AccessControlService\Subscription;

use Acme\AccessControlService\Client\SubscriptionClient;

final class AccessExpiryResolver
{
    public function __construct(private readonly SubscriptionClient $client)
    {
    }

    public function accessExpiresAt(string $accountRef): \DateTimeImmutable
    {
        $sub = $this->client->fetchActive($accountRef);
        if (!$sub) {
            throw new \LogicException('no active subscription');
        }

        $signupDay = (int) (new \DateTimeImmutable($sub['signup_date']))->format('j');
        $period    = new \DateTimeImmutable($sub['period_start']);

        $forward = $period->modify('+1 month');
        $monthLen = (int) $forward->format('t');
        $chosen = min($signupDay, $monthLen);

        $forward = $forward->setDate(
            (int) $forward->format('Y'),
            (int) $forward->format('m'),
            $chosen
        );

        $trial = (int) ($sub['trial_extension_days'] ?? 0);
        if ($trial > 0) {
            $forward = $forward->modify('+' . $trial . ' days');
        }

        return $forward->setTime(23, 59, 59);
    }
}
