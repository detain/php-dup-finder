<?php

declare(strict_types=1);

namespace App\Domain\Returns;

use DateTimeImmutable;

final class RefundPolicy
{
    public const WINDOW_DAYS = 30;

    public static function daysSince(DateTimeImmutable $event, DateTimeImmutable $now): int
    {
        return (int) $now->diff($event)->days;
    }

    public static function isWithinWindow(DateTimeImmutable $event, DateTimeImmutable $now): bool
    {
        return self::daysSince($event, $now) <= self::WINDOW_DAYS;
    }

    public static function deadlineFor(DateTimeImmutable $event): DateTimeImmutable
    {
        return $event->modify('+' . self::WINDOW_DAYS . ' days');
    }

    public static function describe(): string
    {
        return sprintf('%d-day return window', self::WINDOW_DAYS);
    }
}

// ReturnRequestHandler:
// if (!RefundPolicy::isWithinWindow($order->deliveredAt, $now)) {
//     throw new ReturnNotAllowedException('The ' . RefundPolicy::describe() . ' has expired.');
// }
// $rma->deadlineAt = RefundPolicy::deadlineFor($order->deliveredAt);

// InvoiceReverser:
// if (!RefundPolicy::isWithinWindow($invoice->paidAt, $now)) {
//     throw new BillingException('Refunds only allowed within ' . RefundPolicy::WINDOW_DAYS . ' days.');
// }

// CustomerServiceMacros:
// if (RefundPolicy::isWithinWindow($order->deliveredAt, $now)) { /* refund OK macro */ }
// $deadline = RefundPolicy::deadlineFor($order->deliveredAt);
