<?php
declare(strict_types=1);

namespace Acme\Support;

final class TicketIntakeValidator
{
    public function __construct(
        private CustomerLookup $lookup,
        private CategoryRegistry $categories,
        private RateLimiter $limiter,
    ) {
    }

    public function validate(TicketDraft $draft): ValidationOutcome
    {
        if ($draft->subject === '') {
            return ValidationOutcome::reject('subject_missing');
        } else {
            if (!$this->lookup->isActive($draft->customerId)) {
                return ValidationOutcome::reject('customer_inactive');
            } else {
                if (!$this->categories->isKnown($draft->category)) {
                    return ValidationOutcome::reject('category_unknown');
                } else {
                    if ($this->limiter->wasRecentlyFiled($draft->customerId, $draft->category)) {
                        return ValidationOutcome::reject('rate_limited');
                    } else {
                        return ValidationOutcome::accept([
                            'customer' => $draft->customerId,
                            'category' => $draft->category,
                            'subject'  => $draft->subject,
                        ]);
                    }
                }
            }
        }
    }
}
