<?php
declare(strict_types=1);

namespace Acme\Logistics\Reporting;

use Acme\Logistics\Domain\Shipment;
use Acme\Logistics\Domain\ShipmentState;

final class ShipmentStateReporter
{
    public function stateLabel(Shipment $shipment): string
    {
        $state = $shipment->state();

        // identical lexeme stream: switch over enum, return per case, default
        switch ($state) {
            case ShipmentState::Booked:
                return 'awaiting pickup';
            case ShipmentState::InTransit:
                return 'on its way';
            case ShipmentState::OutForDelivery:
                return 'out for delivery today';
            case ShipmentState::Delivered:
                return 'delivered to recipient';
            case ShipmentState::Lost:
                return 'reported lost in transit';
            default:
                return 'unknown shipment state';
        }
    }

    public function manifest(iterable $shipments): array
    {
        $lines = [];
        foreach ($shipments as $shipment) {
            $lines[$shipment->trackingNumber()] = $this->stateLabel($shipment);
        }
        return $lines;
    }
}
