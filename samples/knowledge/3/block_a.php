<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Billing\PaymentProcessor;
use App\Domain\Subscription;
use App\Mail\Mailer;
use App\Repositories\SubscriptionRepository;
use DateTimeImmutable;

final class RenewSubscriptionJob
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private PaymentProcessor $payments,
        private Mailer $mailer,
    ) {}

    public function handle(int $subscriptionId): void
    {
        $sub = $this->subscriptions->findOrFail($subscriptionId);
        $now = new DateTimeImmutable();
        $daysOverdue = $this->daysSince($sub->currentPeriodEnd, $now);

        try {
            $charge = $this->payments->charge($sub->customerId, $sub->priceCents);
            $sub->markRenewed($charge->id, $now);
            $this->subscriptions->save($sub);
            return;
        } catch (\Throwable $e) {
            // Payment failed — fall through to grace handling.
        }

        if ($daysOverdue <= 7) {
            $sub->status = Subscription::STATUS_PAST_DUE;
            $sub->retryAt = $now->modify('+24 hours');
            $this->mailer->send($sub->customerEmail, 'billing.payment_failed_retry', [
                'days_remaining' => 7 - $daysOverdue,
            ]);
        } else {
            $sub->status = Subscription::STATUS_CANCELED;
            $sub->canceledAt = $now;
            $this->mailer->send($sub->customerEmail, 'billing.subscription_canceled', []);
        }

        $this->subscriptions->save($sub);
    }

    private function daysSince(DateTimeImmutable $past, DateTimeImmutable $now): int
    {
        return (int) $now->diff($past)->days;
    }
}
