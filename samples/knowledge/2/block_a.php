<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\JsonResponse;
use App\Services\OrderService;

final class OrderController
{
    public function __construct(private OrderService $orders) {}

    public function store(Request $request): JsonResponse
    {
        $items = $request->input('items', []);
        if ($items === []) {
            return JsonResponse::error('Cart is empty', 422);
        }

        $subtotal = 0;
        foreach ($items as $item) {
            if (!isset($item['price_cents'], $item['quantity'])) {
                return JsonResponse::error('Invalid item payload', 422);
            }
            $subtotal += (int) $item['price_cents'] * (int) $item['quantity'];
        }

        if ($subtotal < 1000) {
            return JsonResponse::error(
                'Order subtotal must be at least $10.00 to proceed.',
                422,
                ['minimum_cents' => 1000, 'current_cents' => $subtotal]
            );
        }

        if ($subtotal > 5_000_000) {
            return JsonResponse::error('Order exceeds the $50,000 limit.', 422);
        }

        $customerId = (int) $request->user()->id;
        $order = $this->orders->create($customerId, $items, $subtotal);

        return JsonResponse::created([
            'order_id' => $order->id,
            'subtotal_cents' => $subtotal,
            'status' => $order->status,
        ]);
    }
}
