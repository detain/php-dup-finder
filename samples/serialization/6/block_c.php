<?php

declare(strict_types=1);

namespace App\Api\Hal;

class OrderHalBuilder
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    public function build(Order $order): array
    {
        $hal = [
            'id' => $order->getId(),
            'user_id' => $order->getUserId(),
            'total' => [
                'amount' => $order->getTotalAmount(),
                'currency' => $order->getCurrency()
            ],
            'status' => $order->getStatus(),
            'items' => $this->formatItems($order->getItems()),
            'created_at' => $order->getCreatedAt()->format('c'),
            'updated_at' => $order->getUpdatedAt()?->format('c'),
            'shipped_at' => $order->getShippedAt()?->format('c'),
            '_links' => [
                'self' => [
                    'href' => $this->buildSelfLink($order->getId())
                ],
                'edit' => [
                    'href' => $this->buildEditLink($order->getId())
                ],
                'user' => [
                    'href' => $this->buildUserLink($order->getUserId())
                ],
                'cancel' => [
                    'href' => $this->buildCancelLink($order->getId())
                ]
            ],
            '_embedded' => []
        ];

        if ($order->getShippingAddress() !== null) {
            $hal['shipping_address'] = $order->getShippingAddress();
        }

        if ($order->getBillingAddress() !== null) {
            $hal['billing_address'] = $order->getBillingAddress();
        }

        $hal['_links']['curies'] = [
            [
                'name' => 'api',
                'href' => $this->baseUrl . '/docs/rels/{rel}',
                'templated' => true
            ]
        ];

        return $hal;
    }

    public function buildCollection(array $orders, int $total, int $page, int $perPage): array
    {
        $hal = [
            'count' => count($orders),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            '_links' => [
                'self' => [
                    'href' => $this->buildCollectionLink($page, $perPage)
                ]
            ],
            '_embedded' => [
                'orders' => array_map(fn(Order $o) => $this->build($o), $orders)
            ]
        ];

        if ($page > 1) {
            $hal['_links']['prev'] = [
                'href' => $this->buildCollectionLink($page - 1, $perPage)
            ];
        }

        if ($page * $perPage < $total) {
            $hal['_links']['next'] = [
                'href' => $this->buildCollectionLink($page + 1, $perPage)
            ];
        }

        $hal['_links']['first'] = [
            'href' => $this->buildCollectionLink(1, $perPage)
        ];

        $hal['_links']['last'] = [
            'href' => $this->buildCollectionLink((int)ceil($total / $perPage), $perPage)
        ];

        $hal['_links']['curies'] = [
            [
                'name' => 'api',
                'href' => $this->baseUrl . '/docs/rels/{rel}',
                'templated' => true
            ]
        ];

        return $hal;
    }

    private function buildSelfLink(string $id): string
    {
        return $this->baseUrl . '/orders/' . $id;
    }

    private function buildEditLink(string $id): string
    {
        return $this->baseUrl . '/orders/' . $id . '/edit';
    }

    private function buildUserLink(string $userId): string
    {
        return $this->baseUrl . '/users/' . $userId;
    }

    private function buildCancelLink(string $id): string
    {
        return $this->baseUrl . '/orders/' . $id . '/cancel';
    }

    private function buildCollectionLink(int $page, int $perPage): string
    {
        return $this->baseUrl . '/orders?page=' . $page . '&per_page=' . $perPage;
    }

    private function formatItems(array $items): array
    {
        return array_map(fn($item) => [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price']
        ], $items);
    }
}
