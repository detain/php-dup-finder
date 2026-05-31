<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Billing;

use PHPUnit\Framework\TestCase;
use App\Http\Controllers\Api\Billing\InvoiceController;
use App\Services\InvoiceService;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;

final class InvoiceControllerTest extends TestCase
{
    private InvoiceController $controller;
    private $mockInvoiceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockInvoiceService = Mockery::mock(InvoiceService::class);
        $this->controller = new InvoiceController($this->mockInvoiceService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testListInvoicesReturns200WithValidParams(): void
    {
        $request = Request::create('/api/billing/invoices', 'GET', [
            'page' => 1,
            'per_page' => 20,
            'status' => 'paid',
        ]);

        $mockInvoices = [
            'data' => [
                ['id' => 1, 'number' => 'INV-2024-001', 'amount' => 150.00],
                ['id' => 2, 'number' => 'INV-2024-002', 'amount' => 275.50],
            ],
            'meta' => [
                'current_page' => 1,
                'per_page' => 20,
                'total' => 2,
            ],
        ];

        $this->mockInvoiceService
            ->shouldReceive('listInvoices')
            ->once()
            ->andReturn($mockInvoices);

        $response = $this->controller->index($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertCount(2, $data['data']);
    }

    public function testListInvoicesReturns400WithInvalidPage(): void
    {
        $request = Request::create('/api/billing/invoices', 'GET', [
            'page' => -1,
        ]);

        $response = $this->controller->index($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('page', $data['message']);
    }

    public function testListInvoicesReturns400WithInvalidPerPage(): void
    {
        $request = Request::create('/api/billing/invoices', 'GET', [
            'per_page' => 500,
        ]);

        $response = $this->controller->index($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('per_page', $data['message']);
    }

    public function testListInvoicesReturns400WithInvalidStatus(): void
    {
        $request = Request::create('/api/billing/invoices', 'GET', [
            'status' => 'invalid_status',
        ]);

        $response = $this->controller->index($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('status', $data['message']);
    }

    public function testShowInvoiceReturns200WhenFound(): void
    {
        $request = Request::create('/api/billing/invoices/1', 'GET');

        $mockInvoice = [
            'id' => 1,
            'number' => 'INV-2024-001',
            'customer' => ['id' => 100, 'name' => 'Acme Corp'],
            'line_items' => [
                ['description' => 'Service A', 'amount' => 100.00],
                ['description' => 'Service B', 'amount' => 50.00],
            ],
            'subtotal' => 150.00,
            'tax' => 15.00,
            'total' => 165.00,
            'status' => 'paid',
        ];

        $this->mockInvoiceService
            ->shouldReceive('getInvoice')
            ->with(1)
            ->once()
            ->andReturn($mockInvoice);

        $response = $this->controller->show($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('INV-2024-001', $data['data']['number']);
    }

    public function testShowInvoiceReturns404WhenNotFound(): void
    {
        $request = Request::create('/api/billing/invoices/999', 'GET');

        $this->mockInvoiceService
            ->shouldReceive('getInvoice')
            ->with(999)
            ->once()
            ->andReturn(null);

        $response = $this->controller->show($request, 999);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not found', strtolower($data['message']));
    }

    public function testCreateInvoiceReturns201WithValidData(): void
    {
        $request = Request::create('/api/billing/invoices', 'POST', [
            'customer_id' => 100,
            'line_items' => [
                ['description' => 'Service A', 'amount' => 100.00],
            ],
            'due_date' => '2024-02-01',
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $mockCreatedInvoice = [
            'id' => 5,
            'number' => 'INV-2024-005',
            'customer_id' => 100,
            'status' => 'draft',
        ];

        $this->mockInvoiceService
            ->shouldReceive('createInvoice')
            ->once()
            ->andReturn($mockCreatedInvoice);

        $response = $this->controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('INV-2024-005', $data['data']['number']);
    }

    public function testCreateInvoiceReturns422WithMissingCustomerId(): void
    {
        $request = Request::create('/api/billing/invoices', 'POST', [
            'line_items' => [
                ['description' => 'Service A', 'amount' => 100.00],
            ],
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('customer_id', $data['errors']);
    }

    public function testCreateInvoiceReturns422WithEmptyLineItems(): void
    {
        $request = Request::create('/api/billing/invoices', 'POST', [
            'customer_id' => 100,
            'line_items' => [],
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('line_items', $data['errors']);
    }

    public function testCreateInvoiceReturns422WithInvalidLineItemAmount(): void
    {
        $request = Request::create('/api/billing/invoices', 'POST', [
            'customer_id' => 100,
            'line_items' => [
                ['description' => 'Service A', 'amount' => -50.00],
            ],
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testUpdateInvoiceReturns200WhenSuccessful(): void
    {
        $request = Request::create('/api/billing/invoices/1', 'PUT', [
            'status' => 'sent',
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $mockUpdatedInvoice = [
            'id' => 1,
            'number' => 'INV-2024-001',
            'status' => 'sent',
        ];

        $this->mockInvoiceService
            ->shouldReceive('updateInvoice')
            ->with(1, ['status' => 'sent'])
            ->once()
            ->andReturn($mockUpdatedInvoice);

        $response = $this->controller->update($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('sent', $data['data']['status']);
    }

    public function testDeleteInvoiceReturns204WhenSuccessful(): void
    {
        $request = Request::create('/api/billing/invoices/1', 'DELETE');

        $this->mockInvoiceService
            ->shouldReceive('deleteInvoice')
            ->with(1)
            ->once()
            ->andReturn(true);

        $response = $this->controller->destroy($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testDeleteInvoiceReturns500WhenDeletionFails(): void
    {
        $request = Request::create('/api/billing/invoices/1', 'DELETE');

        $this->mockInvoiceService
            ->shouldReceive('deleteInvoice')
            ->with(1)
            ->once()
            ->andThrow(new \RuntimeException('Cannot delete invoice with payments'));

        $response = $this->controller->destroy($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }
}
