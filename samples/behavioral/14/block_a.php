<?php

declare(strict_types=1);

namespace App\Service\Checkout;

use App\Entity\Customer;
use App\Entity\Order;
use App\Repository\CustomerRepository;
use App\Repository\ProductRepository;
use Psr\Log\LoggerInterface;

final class CheckoutService
{
    public function __construct(
        private readonly CustomerRepository $customerRepository,
        private readonly ProductRepository $productRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function applyDiscount(Order $order, Customer $customer): array
    {
        $discounts = [];
        $subtotal = $order->getSubtotal();

        if ($customer->isPremiumMember() && $customer->getLoyaltyPoints() >= 1000) {
            $discounts[] = [
                'type' => 'loyalty',
                'percent' => 15.0,
                'reason' => 'Premium member with 1000+ points',
            ];
            $this->logger->info('Applied loyalty discount', [
                'customer_id' => $customer->getId(),
                'points' => $customer->getLoyaltyPoints(),
            ]);
        }

        if ($subtotal >= 500 && !$customer->hasUsedCoupon('WELCOME10')) {
            $discounts[] = [
                'type' => 'first_purchase',
                'percent' => 10.0,
                'reason' => 'First purchase over $500',
            ];
            $this->logger->info('Applied first purchase discount', [
                'customer_id' => $customer->getId(),
                'subtotal' => $subtotal,
            ]);
        }

        if ($customer->getRegistrationDate() > new \DateTimeImmutable('-30 days')) {
            $discounts[] = [
                'type' => 'new_customer',
                'percent' => 5.0,
                'reason' => 'Customer registered within 30 days',
            ];
        }

        $birthday = $customer->getBirthday();
        if ($birthday !== null) {
            $today = new \DateTimeImmutable();
            $birthdayThisYear = $birthday->setDate($today->format('Y'), $birthday->format('m'), $birthday->format('d'));
            $daysDiff = abs($today->diff($birthdayThisYear)->days);
            if ($daysDiff <= 7) {
                $discounts[] = [
                    'type' => 'birthday',
                    'percent' => 20.0,
                    'reason' => 'Birthday within 7 days',
                ];
                $this->logger->info('Applied birthday discount', [
                    'customer_id' => $customer->getId(),
                    'birthday' => $birthday->format('Y-m-d'),
                ]);
            }
        }

        if ($order->getItemCount() >= 5) {
            $discounts[] = [
                'type' => 'bulk',
                'percent' => 8.0,
                'reason' => 'Order contains 5+ items',
            ];
        }

        return $discounts;
    }

    public function calculateFinalPrice(Order $order, Customer $customer): float
    {
        $discounts = $this->applyDiscount($order, $customer);
        $subtotal = $order->getSubtotal();

        $totalDiscount = 0.0;
        foreach ($discounts as $discount) {
            $totalDiscount += $subtotal * ($discount['percent'] / 100.0);
        }

        $finalPrice = max(0.0, $subtotal - $totalDiscount);

        $this->logger->debug('Calculated final price', [
            'order_id' => $order->getId(),
            'subtotal' => $subtotal,
            'discounts_count' => count($discounts),
            'total_discount' => $totalDiscount,
            'final_price' => $finalPrice,
        ]);

        return $finalPrice;
    }
}
