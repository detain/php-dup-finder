<?php
declare(strict_types=1);

namespace Acme\Orders;

final class OrderPlacementValidator
{
    public function __construct(
        private InventoryService $inventory,
        private PricingEngine $pricing,
        private FraudGuard $fraud,
    ) {
    }

    public function validate(OrderDraft $draft): ValidationOutcome
    {
        if ($draft->cart->isEmpty()) {
            return ValidationOutcome::reject('cart_empty');
        } else {
            if (!$this->inventory->everythingInStock($draft->cart)) {
                return ValidationOutcome::reject('stock_missing');
            } else {
                if (!$this->pricing->totalMatches($draft->cart, $draft->expectedTotal)) {
                    return ValidationOutcome::reject('price_drift');
                } else {
                    if ($this->fraud->scoreOf($draft) > 0.85) {
                        return ValidationOutcome::reject('fraud_risk');
                    } else {
                        return ValidationOutcome::accept([
                            'customer' => $draft->customerId,
                            'items'    => $draft->cart->count(),
                            'total'    => $draft->expectedTotal,
                        ]);
                    }
                }
            }
        }
    }
}
