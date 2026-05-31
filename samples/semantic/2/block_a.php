<?php

declare(strict_types=1);

namespace Acme\Warehouse\Picking;

use Acme\Warehouse\Model\Order;
use Acme\Warehouse\Model\PickList;
use Acme\Warehouse\Repository\StockRepository;
use Acme\Warehouse\Exception\NotFulfillableException;

final class PickingController
{
    public function __construct(private StockRepository $stock)
    {
    }

    public function generatePickList(Order $order): PickList
    {
        foreach ($order->lines() as $line) {
            $onHand = $this->stock->onHandFor($line->sku(), $order->warehouseId());
            if ($onHand < $line->quantity()) {
                throw new NotFulfillableException(
                    sprintf('SKU %s short: need %d, have %d', $line->sku(), $line->quantity(), $onHand)
                );
            }
        }

        if ($order->paymentStatus() !== 'captured') {
            throw new NotFulfillableException('Payment not captured for order ' . $order->id());
        }

        $region = $order->shippingAddress()->countryCode();
        $allowed = ['US', 'CA', 'GB', 'DE', 'FR', 'AU'];
        if (!in_array($region, $allowed, true)) {
            throw new NotFulfillableException('Region ' . $region . ' is not serviceable.');
        }

        return PickList::forOrder($order);
    }
}
