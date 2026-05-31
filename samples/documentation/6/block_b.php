<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Entity;

use App\Domain\Orders\Entity\Order;
use App\Domain\Customer\Entity\Customer;
use DateTimeImmutable;

/**
 * Analytics event entity for tracking user interactions and business metrics.
 *
 * EVENT TYPES AND THEIR PROPERTIES:
 *
 * PAGE_VIEW:
 * - url (string): Page URL that was viewed
 * - referrer (string): Referring URL
 * - session_id (string): Browser session identifier
 * - user_id (string|null): User ID if authenticated
 * - timestamp (DateTimeImmutable): When the view occurred
 *
 * PRODUCT_VIEW:
 * - product_id (string): ID of the product viewed
 * - product_name (string): Name of the product
 * - category (string): Product category
 * - price (float): Product price at time of view
 * - session_id (string): Browser session identifier
 * - user_id (string|null): User ID if authenticated
 * - timestamp (DateTimeImmutable): When the view occurred
 *
 * ADD_TO_CART:
 * - product_id (string): ID of added product
 * - quantity (int): Quantity added
 * - unit_price (float): Price per unit
 * - cart_id (string): Shopping cart identifier
 * - session_id (string): Browser session identifier
 * - user_id (string|null): User ID if authenticated
 * - timestamp (DateTimeImmutable): When item was added
 *
 * REMOVE_FROM_CART:
 * - product_id (string): ID of removed product
 * - quantity (int): Quantity that was removed
 * - cart_id (string): Shopping cart identifier
 * - session_id (string): Browser session identifier
 * - user_id (string|null): User ID if authenticated
 * - timestamp (DateTimeImmutable): When item was removed
 *
 * CHECKOUT_START:
 * - cart_id (string): Shopping cart identifier
 * - total_amount (float): Cart total
 * - item_count (int): Number of items in cart
 * - user_id (string): User ID
 * - timestamp (DateTimeImmutable): When checkout started
 *
 * PURCHASE:
 * - order_id (string): Completed order ID
 * - order_total (float): Total amount paid
 * - subtotal (float): Items subtotal
 * - tax (float): Tax amount
 * - shipping_cost (float): Shipping cost
 * - discount_amount (float): Total discounts applied
 * - payment_method (string): Payment method used
 * - user_id (string): User ID
 * - timestamp (DateTimeImmutable): When purchase completed
 *
 * SEARCH:
 * - query (string): Search query string
 * - results_count (int): Number of results returned
 * - filters_applied (array): Active filters when searching
 * - session_id (string): Browser session identifier
 * - user_id (string|null): User ID if authenticated
 * - timestamp (DateTimeImmutable): When search was executed
 *
 * DOCUMENTED IN:
 * - Analytics schema: docs/analytics/schema.md
 * - Event taxonomy: docs/analytics/events.md
 * - Pipeline docs: docs/analytics/pipeline.md
 */
class AnalyticsEvent
{
    public const TYPE_PAGE_VIEW = 'page_view';
    public const TYPE_PRODUCT_VIEW = 'product_view';
    public const TYPE_ADD_TO_CART = 'add_to_cart';
    public const TYPE_REMOVE_FROM_CART = 'remove_from_cart';
    public const TYPE_CHECKOUT_START = 'checkout_start';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_SEARCH = 'search';

    private string $eventId;
    private string $eventType;
    private array $properties;
    private DateTimeImmutable $occurredAt;

    public function __construct(
        string $eventType,
        array $properties,
        ?DateTimeImmutable $occurredAt = null
    ) {
        $this->eventId = bin2hex(random_bytes(16));
        $this->eventType = $eventType;
        $this->properties = $this->validateProperties($eventType, $properties);
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    /**
     * Validate that required properties are present for the event type.
     * Required properties are defined in the event taxonomy document.
     */
    private function validateProperties(string $eventType, array $properties): array
    {
        $requiredProperties = match ($eventType) {
            self::TYPE_PAGE_VIEW => ['url', 'session_id'],
            self::TYPE_PRODUCT_VIEW => ['product_id', 'product_name', 'session_id'],
            self::TYPE_ADD_TO_CART => ['product_id', 'quantity', 'cart_id'],
            self::TYPE_REMOVE_FROM_CART => ['product_id', 'quantity', 'cart_id'],
            self::TYPE_CHECKOUT_START => ['cart_id', 'total_amount', 'user_id'],
            self::TYPE_PURCHASE => ['order_id', 'order_total', 'user_id'],
            self::TYPE_SEARCH => ['query', 'results_count', 'session_id'],
            default => throw new \InvalidArgumentException("Unknown event type: {$eventType}"),
        };

        $missing = array_diff($requiredProperties, array_keys($properties));
        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                "Missing required properties for {$eventType}: " . implode(', ', $missing)
            );
        }

        return $properties;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'properties' => $this->properties,
            'occurred_at' => $this->occurredAt->format(\DateTimeImmutable::ATOM),
        ];
    }
}
