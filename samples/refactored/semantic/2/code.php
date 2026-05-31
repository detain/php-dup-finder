<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use Acme\Shared\Model\Order;

final class OrderFulfillablePolicy
{
    /** @param list<string> $serviceableRegions */
    public function __construct(
        private StockChecker $stockChecker,
        private PaymentChecker $paymentChecker,
        private array $serviceableRegions,
    ) {
    }

    public function evaluate(Order $order): FulfillabilityResult
    {
        if (!$this->stockChecker->hasAllInStock($order->lines(), $order->warehouseId())) {
            return FulfillabilityResult::blocked('insufficient_stock');
        }

        if (!$this->paymentChecker->isCaptured($order->paymentReference())) {
            return FulfillabilityResult::blocked('payment_not_captured');
        }

        $region = $order->shippingAddress()->countryCode();
        if (!in_array($region, $this->serviceableRegions, true)) {
            return FulfillabilityResult::blocked('region_not_served');
        }

        return FulfillabilityResult::ready();
    }
}

final class FulfillabilityResult
{
    private function __construct(public readonly bool $ready, public readonly ?string $reason) {}
    public static function ready(): self { return new self(true, null); }
    public static function blocked(string $reason): self { return new self(false, $reason); }
}
