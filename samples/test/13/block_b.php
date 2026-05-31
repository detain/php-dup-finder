<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Subscription;
use App\Models\Customer;
use App\Services\InvoiceService;
use App\Exceptions\ProrationCalculationException;
use App\Exceptions\PaymentMethodExpiredException;
use Mockery;

class InvoiceGenerationTransactionTest extends TestCase
{
    private InvoiceService $invoiceService;
    private $mockSubscriptionRepository;
    private $mockInvoiceRepository;
    private $mockCustomerRepository;
    private $mockPaymentGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockSubscriptionRepository = Mockery::mock(\App\Repositories\SubscriptionRepository::class);
        $this->mockInvoiceRepository = Mockery::mock(\App\Repositories\InvoiceRepository::class);
        $this->mockCustomerRepository = Mockery::mock(\App\Repositories\CustomerRepository::class);
        $this->mockPaymentGateway = Mockery::mock(\App\Services\PaymentGateway::class);

        $this->invoiceService = new InvoiceService(
            $this->mockSubscriptionRepository,
            $this->mockInvoiceRepository,
            $this->mockCustomerRepository,
            $this->mockPaymentGateway
        );

        // Begin transaction mock setup
        $this->mockInvoiceRepository
            ->shouldReceive('beginTransaction')
            ->once()
            ->andReturnNull();

        $this->mockInvoiceRepository
            ->shouldReceive('commit')
            ->once()
            ->andReturnNull();

        $this->mockInvoiceRepository
            ->shouldReceive('rollback')
            ->zeroOrMoreTimes()
            ->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGeneratesInvoiceForActiveSubscription(): void
    {
        $customer = new Customer([
            'id' => 200,
            'email' => 'billing@example.com',
            'name' => 'Acme Corporation',
            'tier' => 'enterprise',
        ]);

        $subscription = new Subscription([
            'id' => 601,
            'customer_id' => 200,
            'plan_id' => 'enterprise_monthly',
            'status' => 'active',
            'current_period_start' => strtotime('2024-01-01'),
            'current_period_end' => strtotime('2024-02-01'),
            'monthly_price' => 49900,
        ]);

        $this->mockCustomerRepository
            ->shouldReceive('findById')
            ->with(200)
            ->andReturn($customer);

        $this->mockSubscriptionRepository
            ->shouldReceive('findActiveByCustomerId')
            ->with(200)
            ->andReturn([$subscription]);

        $this->mockInvoiceRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($invoiceData) {
                $invoice = new Invoice($invoiceData);
                $invoice->id = 5001;
                return $invoice;
            });

        $this->mockInvoiceRepository
            ->shouldReceive('addLineItem')
            ->once()
            ->andReturn(true);

        $this->mockPaymentGateway
            ->shouldReceive('charge')
            ->once()
            ->andReturn(['transaction_id' => 'txn_abc123', 'amount' => 49900]);

        $invoice = $this->invoiceService->generateInvoice(200, 'monthly');

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertEquals(200, $invoice->customer_id);
        $this->assertEquals(49900, $invoice->total);
        $this->assertEquals('paid', $invoice->status);
    }

    public function testRollsBackOnPaymentFailure(): void
    {
        $customer = new Customer([
            'id' => 201,
            'email' => 'failed_payment@example.com',
            'name' => 'Startup Inc',
        ]);

        $subscription = new Subscription([
            'id' => 602,
            'customer_id' => 201,
            'plan_id' => 'starter_monthly',
            'status' => 'active',
            'monthly_price' => 9900,
        ]);

        $this->mockCustomerRepository
            ->shouldReceive('findById')
            ->with(201)
            ->andReturn($customer);

        $this->mockSubscriptionRepository
            ->shouldReceive('findActiveByCustomerId')
            ->with(201)
            ->andReturn([$subscription]);

        $this->mockInvoiceRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($invoiceData) {
                $invoice = new Invoice($invoiceData);
                $invoice->id = 5002;
                return $invoice;
            });

        $this->mockPaymentGateway
            ->shouldReceive('charge')
            ->once()
            ->andThrow(new PaymentMethodExpiredException('Card expired on file'));

        $this->mockInvoiceRepository
            ->shouldReceive('rollback')
            ->once();

        $this->mockInvoiceRepository
            ->shouldReceive('markAsFailed')
            ->never();

        $this->expectException(PaymentMethodExpiredException::class);
        $this->invoiceService->generateInvoice(201, 'monthly');
    }
}
