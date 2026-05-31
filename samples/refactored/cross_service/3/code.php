<?php
declare(strict_types=1);

namespace Acme\Common\Refund;

/**
 * Shared refund-policy package (acme/refund-policy). OrderService, PaymentService,
 * and CustomerService all map their local order shapes into RefundContext and call
 * decide() so a single, versioned policy governs eligibility everywhere.
 */
final class RefundPolicy
{
    public const WINDOW_DAYS = 30;
    public const TERMINAL_STATUSES = ['refunded', 'partially_refunded'];

    public function decide(RefundContext $ctx): RefundDecision
    {
        $age = (int) $ctx->placedAt->diff($ctx->now)->days;
        if ($age > self::WINDOW_DAYS) {
            return RefundDecision::deny('beyond_window');
        }

        if (in_array($ctx->orderStatus, self::TERMINAL_STATUSES, true)) {
            return RefundDecision::deny('already_refunded');
        }

        if ($ctx->paymentState !== 'captured') {
            return RefundDecision::deny('not_captured');
        }

        foreach ($ctx->lineItems as $line) {
            if ($line->finalSale) {
                return RefundDecision::deny('contains_final_sale');
            }
        }

        return RefundDecision::allow();
    }
}
