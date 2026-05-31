<?php

declare(strict_types=1);

namespace Acme\Ops\Dispatch;

use Acme\Ops\Adapter\WarehouseAdapter;
use Acme\Ops\Adapter\PaymentFacade;
use Acme\Ops\Adapter\ShippingZones;
use Acme\Ops\Model\Order;
use Acme\Ops\Model\DispatchPlan;

final class DispatchPlanner
{
    public function __construct(
        private WarehouseAdapter $warehouse,
        private PaymentFacade $payments,
        private ShippingZones $zones,
    ) {
    }

    public function plan(Order $order): DispatchPlan
    {
        $availability = $this->warehouse->reserveAll($order->lines());
        if (!$availability->fullyReserved()) {
            $order->markBackordered();
            throw new \RuntimeException('Cannot dispatch: items backordered.');
        }

        $paid = $this->payments->isSettled($order->paymentReference());
        if (!$paid) {
            $availability->releaseAll();
            throw new \RuntimeException('Cannot dispatch: payment not settled.');
        }

        if (!$this->zones->serves($order->shippingAddress())) {
            $availability->releaseAll();
            throw new \RuntimeException('Cannot dispatch: destination outside service area.');
        }

        return DispatchPlan::create($order, $availability);
    }
}
