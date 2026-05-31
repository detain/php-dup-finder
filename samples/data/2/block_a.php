<?php
declare(strict_types=1);

namespace App\Billing\Charges;

use App\Database\Connection;
use App\Payments\StripeClient;
use Psr\Log\LoggerInterface;

final class SubscriptionChargeService
{
    public function __construct(
        private Connection $db,
        private StripeClient $stripe,
        private LoggerInterface $logger,
    ) {
    }

    public function chargeMonthly(int $subscriptionId): array
    {
        $sub = $this->db->fetchOne(
            'SELECT id, customer_id, plan_id, amount_cents, status FROM subscriptions WHERE id = ?',
            [$subscriptionId]
        );

        if ($sub === null) {
            throw new \RuntimeException('Subscription not found');
        }

        if ($sub['status'] !== 'active') {
            $this->logger->info('Skipping inactive subscription', ['id' => $subscriptionId]);
            return ['skipped' => true];
        }

        $customer = $this->db->fetchOne(
            'SELECT stripe_customer_id, default_payment_method FROM customers WHERE id = ?',
            [(int)$sub['customer_id']]
        );

        if (empty($customer['default_payment_method'])) {
            throw new \DomainException('No payment method on file');
        }

        $charge = $this->stripe->createCharge([
            'amount'         => (int)$sub['amount_cents'],
            'currency'       => 'USD',
            'customer'       => $customer['stripe_customer_id'],
            'payment_method' => $customer['default_payment_method'],
            'description'    => 'Monthly subscription ' . $sub['plan_id'],
        ]);

        $this->db->execute(
            'INSERT INTO charges (subscription_id, amount_cents, currency, stripe_id, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$subscriptionId, (int)$sub['amount_cents'], 'USD', $charge['id']]
        );

        return [
            'charge_id' => $charge['id'],
            'amount'    => (int)$sub['amount_cents'],
            'currency'  => 'USD',
        ];
    }
}
