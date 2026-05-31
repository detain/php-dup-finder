<?php
declare(strict_types=1);

namespace Acme\OrderService\Inventory;

use Acme\OrderService\Client\InventoryClient;

final class OrderReservationValidator
{
    public function __construct(private readonly InventoryClient $client)
    {
    }

    public function validate(array $orderLines): array
    {
        $issues = [];

        foreach ($orderLines as $line) {
            $sku = (string) $line['sku'];
            $qty = (int) $line['qty'];

            $entry = $this->client->lookup($sku);
            if ($entry === null) {
                $issues[] = ['sku' => $sku, 'reason' => 'missing_sku'];
                continue;
            }

            $onHand = (int) $entry['on_hand'];
            $reserved = (int) $entry['reserved'];
            $safety = (int) ($entry['safety_stock'] ?? 0);

            $effective = $onHand - $reserved - $safety;

            if (($entry['tier'] ?? '') === 'tier-1') {
                $effective += 50;
            }

            if ($effective < $qty) {
                $issues[] = ['sku' => $sku, 'reason' => 'insufficient'];
            }
        }

        return $issues;
    }
}
