<?php

declare(strict_types=1);

namespace App\Services\Grpc;

use App\Services\OrderService;
use App\Grpc\OrderServiceInterface;
use App\Grpc\OrderRequest;
use App\Grpc\OrderResponse;
use App\Grpc\OrderListResponse;
use App\Grpc\EmptyRequest;
use Spiral\GRPC;
use Spiral\GRPC\Exception\NotFoundException;
use Spiral\GRPC\Exception\BadRequestException;

class OrderGrpcService implements OrderServiceInterface
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function GetOrder(GRPC\ContextInterface $ctx, OrderRequest $in): OrderResponse
    {
        // Validate request
        if (!$in->getId() && !$in->getOrderNumber()) {
            throw new BadRequestException('Order ID or order number is required');
        }

        // Get order
        $order = $in->getId()
            ? $this->orderService->findById($in->getId())
            : $this->orderService->findByOrderNumber($in->getOrderNumber());

        if (!$order) {
            throw new NotFoundException('Order not found');
        }

        // Build response
        return new OrderResponse([
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'customer_email' => $order['customer_email'],
            'status' => $order['status'],
            'total' => $order['total'],
            'currency' => $order['currency'] ?? 'USD',
            'created_at' => $order['created_at'],
        ]);
    }

    public function ListOrders(GRPC\ContextInterface $ctx, OrderListRequest $in): OrderListResponse
    {
        // Parse pagination
        $limit = $in->getLimit() ?: 50;
        $offset = $in->getOffset() ?: 0;

        // Get filters
        $filters = [];
        if ($in->getStatus()) {
            $filters['status'] = $in->getStatus();
        }
        if ($in->getCustomerId()) {
            $filters['customer_id'] = $in->getCustomerId();
        }
        if ($in->getCreatedAfter()) {
            $filters['created_after'] = $in->getCreatedAfter();
        }
        if ($in->getCreatedBefore()) {
            $filters['created_before'] = $in->getCreatedBefore();
        }

        // Get orders
        $orders = $this->orderService->searchOrders('', $filters, $limit, $offset);
        $total = $this->orderService->countSearchResults('', $filters);

        // Build response
        $orderProtos = array_map(function ($order) {
            return new OrderResponse([
                'id' => $order['id'],
                'order_number' => $order['order_number'],
                'customer_email' => $order['customer_email'],
                'status' => $order['status'],
                'total' => $order['total'],
            ]);
        }, $orders);

        return new OrderListResponse([
            'orders' => $orderProtos,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function CreateOrder(GRPC\ContextInterface $ctx, CreateOrderRequest $in): OrderResponse
    {
        // Validate request
        $validationErrors = [];

        if (!$in->getCustomerEmail()) {
            $validationErrors[] = 'Customer email is required';
        } elseif (!filter_var($in->getCustomerEmail(), FILTER_VALIDATE_EMAIL)) {
            $validationErrors[] = 'Invalid customer email format';
        }

        if (!$in->getItems()) {
            $validationErrors[] = 'Order items are required';
        }

        if (!empty($validationErrors)) {
            throw new BadRequestException(implode(', ', $validationErrors));
        }

        // Build order data
        $orderData = [
            'customer_email' => $in->getCustomerEmail(),
            'items' => [],
            'shipping_address' => $in->getShippingAddress(),
        ];

        foreach ($in->getItems() as $item) {
            $orderData['items'][] = [
                'product_id' => $item->getProductId(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
            ];
        }

        try {
            $order = $this->orderService->createOrder($orderData);
        } catch (\App\Exceptions\InsufficientInventoryException $e) {
            throw new BadRequestException('Insufficient inventory for one or more items');
        }

        return new OrderResponse([
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'total' => $order['total'],
        ]);
    }

    public function UpdateOrderStatus(GRPC\ContextInterface $ctx, UpdateOrderStatusRequest $in): OrderResponse
    {
        // Validate request
        if (!$in->getId()) {
            throw new BadRequestException('Order ID is required');
        }

        if (!$in->getStatus()) {
            throw new BadRequestException('Status is required');
        }

        $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        if (!in_array($in->getStatus(), $validStatuses)) {
            throw new BadRequestException('Invalid status value');
        }

        $order = $this->orderService->findById($in->getId());
        if (!$order) {
            throw new NotFoundException('Order not found');
        }

        $updatedOrder = $this->orderService->updateStatus($in->getId(), $in->getStatus());

        return new OrderResponse([
            'id' => $updatedOrder['id'],
            'order_number' => $updatedOrder['order_number'],
            'status' => $updatedOrder['status'],
        ]);
    }

    public function CancelOrder(GRPC\ContextInterface $ctx, OrderRequest $in): EmptyResponse
    {
        if (!$in->getId()) {
            throw new BadRequestException('Order ID is required');
        }

        $order = $this->orderService->findById($in->getId());
        if (!$order) {
            throw new NotFoundException('Order not found');
        }

        if ($order['status'] === 'delivered') {
            throw new BadRequestException('Cannot cancel a delivered order');
        }

        $this->orderService->cancel($in->getId());

        return new EmptyResponse();
    }
}
