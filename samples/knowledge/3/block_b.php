<?php

declare(strict_types=1);

namespace App\Communications;

use App\Repositories\SubscriptionRepository;
use App\Templating\TemplateEngine;
use App\Transport\EmailTransport;
use DateTimeImmutable;

final class DunningEmailScheduler
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private TemplateEngine $templates,
        private EmailTransport $transport,
    ) {}

    public function sendDailyBatch(): int
    {
        $sent = 0;
        $now = new DateTimeImmutable();

        foreach ($this->subscriptions->pastDue() as $sub) {
            $daysOverdue = (int) $now->diff($sub->currentPeriodEnd)->days;
            $daysRemaining = 7 - $daysOverdue;

            if ($daysRemaining > 5) {
                $template = 'dunning.friendly';
                $subject = 'We could not process your payment';
            } elseif ($daysRemaining > 2) {
                $template = 'dunning.urgent';
                $subject = sprintf('Your account will be locked in %d days', $daysRemaining);
            } elseif ($daysRemaining >= 0) {
                $template = 'dunning.final_warning';
                $subject = 'Final notice: account access ending soon';
            } else {
                // Past 7-day grace — let the lockout job handle it.
                continue;
            }

            $body = $this->templates->render($template, [
                'first_name' => $sub->firstName,
                'days_remaining' => max(0, $daysRemaining),
                'amount_due' => $sub->priceCents / 100,
            ]);

            $this->transport->send($sub->customerEmail, $subject, $body);
            $sent++;
        }

        return $sent;
    }
}
