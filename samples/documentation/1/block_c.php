<?php

declare(strict_types=1);

namespace App\Api\Controllers\Order;

use App\Application\DTOs\Order\CreateOrderRequest;
use App\Application\DTOs\Order\OrderStatusUpdateRequest;
use App\Application\Services\OrderService;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * Order management API handling order creation, status updates,
 * fulfillment processing, and order lifecycle management.
 *
 * @Route("/api/v1/orders", name="api_v1_orders_")
 * @OA\Tag(name="Orders", description="Order processing and fulfillment")
 */
class OrderController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create a new order from the customer's cart.
     *
     * @param CreateOrderRequest $request The order creation payload
     *   - customerId: string (required) UUID of the placing customer
     *   - items: array (required) Array of order items
     *     - productId: string Product UUID
     *     - quantity: int Positive integer
     *     - unitPrice: float Price at time of order
     *   - shippingAddressId: string (required) UUID of saved address
     *   - billingAddressId: string (required) UUID of billing address
     *   - paymentMethodId: string (required) Selected payment method
     *   - shippingMethod: string (required) One of: standard, express, overnight, international
     *   - couponCode: string (optional) Discount coupon code
     *   - notes: string (optional) Special instructions, max 500 chars
     * @return JsonResponse 201 with orderId, orderNumber, and totalAmount
     *   - orderId: string UUID of created order
     *   - orderNumber: string Human-readable order number (ORD-YYYYMMDD-XXXX)
     *   - totalAmount: float Grand total including shipping and tax
     *   - estimatedDelivery: string ISO 8601 date
     * @throws ValidationException 422 if items unavailable or invalid
     * @throws DomainException 400 if addresses don't belong to customer
     * @throws PaymentException 402 if payment authorization fails
     *
     * @OA\Post(
     *   path="/api/v1/orders",
     *   summary="Create a new order",
     *   tags={"Orders"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"customerId", "items", "shippingAddressId", "billingAddressId", "paymentMethodId", "shippingMethod"},
     *       @OA\Property(property="customerId", type="string", format="uuid"),
     *       @OA\Property(property="items", type="array",
     *         @OA\Items(
     *           @OA\Property(property="productId", type="string", format="uuid"),
     *           @OA\Property(property="quantity", type="integer", minimum=1),
     *           @OA\Property(property="unitPrice", type="number", format="float")
     *         )
     *       ),
     *       @OA\Property(property="shippingAddressId", type="string", format="uuid"),
     *       @OA\Property(property="billingAddressId", type="string", format="uuid"),
     *       @OA\Property(property="paymentMethodId", type="string", format="uuid"),
     *       @OA\Property(property="shippingMethod", type="string", enum={"standard","express","overnight","international"}),
     *       @OA\Property(property="couponCode", type="string"),
     *       @OA\Property(property="notes", type="string", maxLength=500)
     *     )
     *   ),
     *   @OA\Response(response=201, description="Order created successfully"),
     *   @OA\Response(response=422, description="Validation error"),
     *   @OA\Response(response=402, description="Payment failed")
     * )
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(CreateOrderRequest $request): JsonResponse
    {
        $this->logger->info('Creating new order', [
            'customer_id' => $request->getCustomerId(),
            'item_count' => count($request->getItems()),
            'shipping_method' => $request->getShippingMethod(),
        ]);

        try {
            $order = $this->orderService->createOrder($request->toDomainCommand());

            $this->logger->info('Order created successfully', [
                'order_id' => $order->getId()->toString(),
                'order_number' => $order->getOrderNumber(),
                'total' => $order->getTotalAmount()->getAmount(),
            ]);

            return new JsonResponse([
                'orderId' => $order->getId()->toString(),
                'orderNumber' => $order->getOrderNumber(),
                'totalAmount' => $order->getTotalAmount()->getAmount(),
                'currency' => $order->getTotalAmount()->getCurrency(),
                'estimatedDelivery' => $order->getEstimatedDelivery()->format(\DateTimeImmutable::ATOM),
            ], Response::HTTP_CREATED);

        } catch (InsufficientInventoryException $e) {
            $this->logger->warning('Order failed - insufficient inventory', [
                'product_id' => $e->getProductId(),
                'requested' => $e->getRequestedQuantity(),
                'available' => $e->getAvailableQuantity(),
            ]);
            return new JsonResponse([
                'error' => 'insufficient_inventory',
                'message' => 'Some items are no longer available in the requested quantity',
                'unavailable_items' => $e->getUnavailableItems(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (AddressNotOwnedException $e) {
            $this->logger->error('Order failed - address not owned by customer', [
                'customer_id' => $request->getCustomerId(),
                'address_id' => $e->getAddressId(),
            ]);
            return new JsonResponse([
                'error' => 'invalid_address',
                'message' => 'One or more addresses are invalid',
            ], Response::HTTP_BAD_REQUEST);

        } catch (PaymentFailedException $e) {
            $this->logger->error('Order failed - payment declined', [
                'customer_id' => $request->getCustomerId(),
                'payment_method_id' => $request->getPaymentMethodId(),
                'decline_code' => $e->getDeclineCode(),
            ]);
            return new JsonResponse([
                'error' => 'payment_failed',
                'message' => 'Payment could not be authorized',
                'decline_code' => $e->getDeclineCode(),
            ], Response::HTTP_PAYMENT_REQUIRED);
        }
    }
}
