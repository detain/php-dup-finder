<?php

declare(strict_types=1);

namespace App\Domain\Orders\Entity;

use App\Domain\Orders\ValueObject\OrderId;
use App\Domain\Orders\ValueObject\OrderStatus;
use App\Domain\Orders\ValueObject\Money;

/**
 * Eloquent model for Order entity.
 * This model definition is duplicated from:
 * - Database table: orders, order_items
 * - API DTOs: OrderCreateRequest, OrderResponse
 * - External API schemas
 *
 * @property string $id
 * @property string $order_number
 * @property string $customer_id
 * @property string $status
 * @property float $subtotal
 * @property float $tax_amount
 * @property float $shipping_amount
 * @property float $discount_amount
 * @property float $total_amount
 * @property string $currency
 * @property string $shipping_address_id
 * @property string $billing_address_id
 * @property string $payment_method_id
 * @property string|null $notes
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Order extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'order_number',
        'customer_id',
        'status',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'total_amount',
        'currency',
        'shipping_address_id',
        'billing_address_id',
        'payment_method_id',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'tax_amount' => 'float',
        'shipping_amount' => 'float',
        'discount_amount' => 'float',
        'total_amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
        'currency' => 'USD',
    ];

    /**
     * Get all items for this order.
     */
    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * Get the customer for this order.
     */
    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get the shipping address.
     */
    public function shippingAddress(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    /**
     * Get the billing address.
     */
    public function billingAddress(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    /**
     * Get the payment method.
     */
    public function paymentMethod(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * Get all status transitions for this order.
     */
    public function statusHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id');
    }

    /**
     * Calculate order totals from items.
     */
    public function calculateTotals(): void
    {
        $this->subtotal = $this->items->sum(function ($item) {
            return $item->quantity * $item->unit_price;
        });

        $this->tax_amount = $this->subtotal * config('tax.rate', 0.08);
        $this->total_amount = $this->subtotal + $this->tax_amount + $this->shipping_amount - $this->discount_amount;
    }

    /**
     * Check if order can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Check if order has been shipped.
     */
    public function hasBeenShipped(): bool
    {
        return in_array($this->status, ['shipped', 'delivered', 'completed']);
    }
}

/**
 * Order item model.
 *
 * @property string $id
 * @property string $order_id
 * @property string $product_id
 * @property string $product_name
 * @property int $quantity
 * @property float $unit_price
 * @property float $subtotal
 */
class OrderItem extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'order_items';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'float',
        'subtotal' => 'float',
    ];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
