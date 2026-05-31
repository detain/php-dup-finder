<?php
declare(strict_types=1);

namespace Acme\Common\Reporting;

/**
 * Generic enum-to-label translator. Each domain supplies a constant map.
 *
 * @template T of \BackedEnum|\UnitEnum
 */
final class EnumLabelMapper
{
    /**
     * @param array<string,string> $labels keyed by enum case name
     */
    public function __construct(
        private readonly array $labels,
        private readonly string $fallback = 'unknown',
    ) {
    }

    public function label(\BackedEnum|\UnitEnum $case): string
    {
        return $this->labels[$case->name] ?? $this->fallback;
    }
}

// Per-domain mappers
// $orderLabels = new EnumLabelMapper([
//     'Pending'   => 'awaiting payment',
//     'Paid'      => 'paid in full',
//     'Shipped'   => 'shipped to customer',
//     'Delivered' => 'delivered successfully',
//     'Cancelled' => 'cancelled by user',
// ], 'unknown order status');
//
// $ticketLabels   = new EnumLabelMapper([...], 'unclassified ticket');
// $shipmentLabels = new EnumLabelMapper([...], 'unknown shipment state');
//
// $orderLabels->label($order->status());
