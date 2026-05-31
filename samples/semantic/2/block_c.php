<?php

declare(strict_types=1);

namespace Acme\Storefront\OrderApi;

use Acme\Storefront\Model\Order;
use Acme\Storefront\Service\NotificationBus;
use Acme\Storefront\Service\AnalyticsTracker;

final class OrderReadinessHandler
{
    public function __construct(
        private NotificationBus $bus,
        private AnalyticsTracker $analytics,
    ) {
    }

    public function handle(Order $order): array
    {
        if ($order->fulfillmentReadiness()->isReady()) {
            $this->bus->publish('order.ready', ['id' => $order->id()]);
            $this->analytics->track('order_ready', $order->id());

            return [
                'status' => 'ready',
                'eta'    => $order->estimatedShipDate(),
            ];
        }

        $reason = $order->fulfillmentReadiness()->blockingReason();
        $this->analytics->track('order_blocked', $order->id(), ['reason' => $reason]);

        return [
            'status' => 'blocked',
            'reason' => $reason,
        ];
    }
}
