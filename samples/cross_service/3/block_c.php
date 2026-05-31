<?php
declare(strict_types=1);

namespace Acme\CustomerService\Refund;

use Acme\CustomerService\Client\OrderClient;

final class AgentRefundAdvisor
{
    public function __construct(private readonly OrderClient $orderClient)
    {
    }

    public function advise(string $orderRef): string
    {
        $payload = $this->orderClient->fetch($orderRef);
        if (!$payload) {
            return 'Cannot refund: order not found.';
        }

        $purchased = new \DateTimeImmutable($payload['placed_at']);
        $delta = (int) $purchased->diff(new \DateTimeImmutable())->days;
        if ($delta > 30) {
            return 'Cannot refund: outside 30-day window.';
        }

        if ($payload['status'] === 'refunded' || $payload['status'] === 'partially_refunded') {
            return 'Cannot refund: already refunded.';
        }

        if ($payload['payment_state'] !== 'captured') {
            return 'Cannot refund: payment not yet captured.';
        }

        foreach ($payload['line_items'] as $li) {
            if ($li['final_sale']) {
                return 'Cannot refund: contains final-sale items.';
            }
        }

        return 'OK to issue refund.';
    }
}
