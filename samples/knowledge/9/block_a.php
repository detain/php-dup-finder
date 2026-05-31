<?php

declare(strict_types=1);

namespace App\Returns;

use App\Domain\Order;
use App\Domain\ReturnRequest;
use App\Exceptions\ReturnNotAllowedException;
use App\Repositories\OrderRepository;
use App\Repositories\ReturnRequestRepository;
use DateTimeImmutable;

final class ReturnRequestHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ReturnRequestRepository $returns,
    ) {}

    public function open(int $orderId, int $customerId, string $reason): ReturnRequest
    {
        $order = $this->orders->findOrFail($orderId);

        if ($order->customerId !== $customerId) {
            throw new ReturnNotAllowedException('You do not own this order.');
        }

        if ($order->status !== 'delivered') {
            throw new ReturnNotAllowedException('Only delivered orders can be returned.');
        }

        $deliveredAt = $order->deliveredAt;
        $now = new DateTimeImmutable();
        $daysSinceDelivery = (int) $now->diff($deliveredAt)->days;

        if ($daysSinceDelivery > 30) {
            throw new ReturnNotAllowedException(
                'The 30-day return window has expired for this order.'
            );
        }

        $rma = new ReturnRequest();
        $rma->orderId = $order->id;
        $rma->customerId = $customerId;
        $rma->reason = $reason;
        $rma->status = 'pending_review';
        $rma->openedAt = $now;
        $rma->deadlineAt = $deliveredAt->modify('+30 days');

        $this->returns->save($rma);
        return $rma;
    }
}
