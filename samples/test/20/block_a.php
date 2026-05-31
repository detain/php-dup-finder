<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use PHPUnit\Framework\TestCase;
use App\Services\BillingService;
use App\Services\PaymentGateway;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Customer;
use App\Exceptions\PaymentFailedException;
use App\Exceptions\SubscriptionExpiredException;
use Doctrine\DBAL\Connection;
use Mockery;

class BillingServiceIntegrationTest extends TestCase
{
    private BillingService $billingService;
    private Connection $connection;
    private PaymentGateway $paymentGateway;
    private InvoiceService $invoiceService;
    private SubscriptionService $subscriptionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createConnectionMock();
        $this->paymentGateway = Mockery::mock(PaymentGateway::class);
        $this->invoiceService = Mockery::mock(InvoiceService::class);
        $this->subscriptionService = Mockery::mock(SubscriptionService::class);

        $this->billingService = new BillingService(
            $this->connection,
            $this->paymentGateway,
            $this->invoiceService,
            $this->subscriptionService
        );

        $this->connection->shouldReceive('beginTransaction')->andReturnNull();
        $this->connection->shouldReceive('commit')->andReturnNull();
        $this->connection->shouldReceive('rollback')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createConnectionMock(): Connection
    {
        $mock = Mockery::mock(Connection::class);

        $mock->shouldReceive('executeQuery')
            ->andReturn(Mockery::mock(\Doctrine\DBAL\Result::class));

        $mock->shouldReceive('executeStatement')
            ->andReturn(1);

        $mock->shouldReceive('createQueryBuilder')
            ->andReturn(Mockery::mock(\Doctrine\DBAL\Query\QueryBuilder::class));

        return $mock;
    }

    private function createTestCustomer(array $overrides = []): Customer
    {
        $defaults = [
            'id' => 1,
            'email' => 'customer@example.com',
            'name' => 'Test Customer',
            'tier' => 'standard',
            'credit_limit' => 10000,
            'balance' => 0,
            'created_at' => new \DateTimeImmutable(),
        ];

        return new Customer(array_merge($defaults, $overrides));
    }

    private function createTestInvoice(array $overrides = []): Invoice
    {
        $defaults = [
            'id' => 1,
            'customer_id' => 1,
            'invoice_number' => 'INV-001',
            'amount' => 9999,
            'status' => 'pending',
            'due_date' => new \DateTimeImmutable('+30 days'),
            'created_at' => new \DateTimeImmutable(),
        ];

        return new Invoice(array_merge($defaults, $overrides));
    }

    private function createTestSubscription(array $overrides = []): Subscription
    {
        $defaults = [
            'id' => 1,
            'customer_id' => 1,
            'plan_id' => 'monthly_basic',
            'status' => 'active',
            'current_period_start' => new \DateTimeImmutable('-15 days'),
            'current_period_end' => new \DateTimeImmutable('+15 days'),
            'next_billing_date' => new \DateTimeImmutable('+15 days'),
            'amount' => 2999,
        ];

        return new Subscription(array_merge($defaults, $overrides));
    }

    public function testProcessPaymentForInvoice(): void
    {
        $customer = $this->createTestCustomer();
        $invoice = $this->createTestInvoice(['customer_id' => 1]);

        $this->invoiceService
            ->shouldReceive('findPendingByCustomerId')
            ->with(1)
            ->andReturn($invoice);

        $this->paymentGateway
            ->shouldReceive('charge')
            ->with(9999, 'visa_ending_4242', Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'transaction_id' => 'txn_123',
                'amount' => 9999,
            ]);

        $this->invoiceService
            ->shouldReceive('markAsPaid')
            ->with(1, 'txn_123')
            ->once();

        $this->connection
            ->shouldReceive('commit')
            ->once();

        $result = $this->billingService->processPayment(1, 'visa_ending_4242');

        $this->assertTrue($result);
        $this->assertEquals('paid', $invoice->status);
    }

    public function testHandlesPaymentFailure(): void
    {
        $customer = $this->createTestCustomer();
        $invoice = $this->createTestInvoice(['customer_id' => 1]);

        $this->invoiceService
            ->shouldReceive('findPendingByCustomerId')
            ->with(1)
            ->andReturn($invoice);

        $this->paymentGateway
            ->shouldReceive('charge')
            ->andReturn([
                'success' => false,
                'error_code' => 'card_declined',
                'error_message' => 'Your card was declined',
            ]);

        $this->connection
            ->shouldReceive('rollback')
            ->once();

        $this->expectException(PaymentFailedException::class);
        $this->billingService->processPayment(1, 'visa_ending_4242');
    }

    public function testChargesRecurringSubscription(): void
    {
        $customer = $this->createTestCustomer(['tier' => 'premium']);
        $subscription = $this->createTestSubscription([
            'customer_id' => 1,
            'next_billing_date' => new \DateTimeImmutable(),
        ]);

        $this->subscriptionService
            ->shouldReceive('findDueForBilling')
            ->andReturn([$subscription]);

        $this->paymentGateway
            ->shouldReceive('charge')
            ->with(2999, Mockery::type('string'), Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'transaction_id' => 'txn_456',
            ]);

        $this->subscriptionService
            ->shouldReceive('recordPayment')
            ->once();

        $this->subscriptionService
            ->shouldReceive('extendPeriod')
            ->with(1, Mockery::type(\DateTimeImmutable::class))
            ->once();

        $result = $this->billingService->processRecurringBilling();

        $this->assertTrue($result);
        $this->assertEquals('txn_456', $subscription->last_payment_id);
    }

    public function testHandlesExpiredSubscription(): void
    {
        $subscription = $this->createTestSubscription([
            'status' => 'expired',
            'current_period_end' => new \DateTimeImmutable('-1 day'),
        ]);

        $this->subscriptionService
            ->shouldReceive('findDueForBilling')
            ->andReturn([$subscription]);

        $this->expectException(SubscriptionExpiredException::class);
        $this->billingService->processRecurringBilling();
    }
}
