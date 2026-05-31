<?php

declare(strict_types=1);

namespace App\Api\Transform;

class OrderApiTransformer
{
    public function transform(Order $order, string $format = 'default'): array
    {
        $base = [
            'type' => 'order',
            'id' => $order->getId(),
            'attributes' => [
                'status' => $order->getStatus(),
                'total' => [
                    'amount' => $order->getTotalAmount(),
                    'currency' => $order->getCurrency()
                ],
                'items' => array_map(fn($item) => [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price']
                ], $order->getItems()),
                'shipping_address' => $order->getShippingAddress(),
                'billing_address' => $order->getBillingAddress(),
                'created_at' => $order->getCreatedAt()->format('c'),
                'updated_at' => $order->getUpdatedAt()?->format('c'),
                'shipped_at' => $order->getShippedAt()?->format('c')
            ],
            'relationships' => [
                'user' => [
                    'data' => [
                        'type' => 'user',
                        'id' => $order->getUserId()
                    ]
                ]
            ]
        ];

        if ($format === 'compact') {
            return [
                'type' => 'order',
                'id' => $order->getId(),
                'attributes' => [
                    'status' => $order->getStatus(),
                    'total' => $order->getTotalAmount(),
                    'currency' => $order->getCurrency()
                ]
            ];
        }

        if ($format === 'detailed') {
            $base['meta'] = [
                'item_count' => count($order->getItems()),
                'created_timestamp' => $order->getCreatedAt()->getTimestamp()
            ];
        }

        return $base;
    }

    public function transformMany(array $orders, string $format = 'default'): array
    {
        return [
            'data' => array_map(fn(Order $o) => $this->transform($o, $format), $orders),
            'meta' => [
                'count' => count($orders),
                'format' => $format
            ]
        ];
    }

    public function transformForIndex(array $orders): array
    {
        return [
            'data' => array_map(function (Order $o) {
                return [
                    'type' => 'order',
                    'id' => $o->getId(),
                    'attributes' => [
                        'status' => $o->getStatus(),
                        'total' => $o->getTotalAmount(),
                        'currency' => $o->getCurrency(),
                        'item_count' => count($o->getItems())
                    ]
                ];
            }, $orders),
            'meta' => [
                'count' => count($orders)
            ]
        ];
    }

    public function transformForShow(Order $order): array
    {
        return [
            'data' => $this->transform($order, 'detailed'),
            'meta' => [
                'timestamp' => time()
            ]
        ];
    }

    public function transformForCreate(Order $order): array
    {
        return [
            'data' => [
                'type' => 'order',
                'id' => $order->getId(),
                'attributes' => [
                    'status' => $order->getStatus(),
                    'total' => $order->getTotalAmount(),
                    'currency' => $order->getCurrency(),
                    'created_at' => $order->getCreatedAt()->format('c')
                ]
            ]
        ];
    }

    public function transformForUpdate(Order $order): array
    {
        return [
            'data' => [
                'type' => 'order',
                'id' => $order->getId(),
                'attributes' => [
                    'status' => $order->getStatus(),
                    'updated_at' => $order->getUpdatedAt()?->format('c')
                ]
            ]
        ];
    }

    public function addPagination(array $orders, int $total, int $page, int $perPage): array
    {
        $lastPage = (int)ceil($total / $perPage);

        return [
            'data' => array_map(fn(Order $o) => $this->transform($o), $orders),
            'meta' => [
                'count' => count($orders),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage
            ],
            'links' => [
                'self' => '/orders?page=' . $page,
                'first' => '/orders?page=1',
                'prev' => $page > 1 ? '/orders?page=' . ($page - 1) : null,
                'next' => $page < $lastPage ? '/orders?page=' . ($page + 1) : null,
                'last' => '/orders?page=' . $lastPage
            ]
        ];
    }
}
