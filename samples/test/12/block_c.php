<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Crm;

use PHPUnit\Framework\TestCase;
use App\Http\Controllers\Api\Crm\ContactController;
use App\Services\ContactService;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;

final class ContactControllerTest extends TestCase
{
    private ContactController $controller;
    private $mockContactService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockContactService = Mockery::mock(ContactService::class);
        $this->controller = new ContactController($this->mockContactService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSearchContactsReturns200WithQuery(): void
    {
        $request = Request::create('/api/crm/contacts/search', 'GET', [
            'q' => 'John Doe',
            'page' => 1,
        ]);

        $mockContacts = [
            'data' => [
                ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com'],
                ['id' => 2, 'first_name' => 'Johnny', 'last_name' => 'Doe', 'email' => 'johnny@example.com'],
            ],
            'meta' => ['current_page' => 1, 'total' => 2],
        ];

        $this->mockContactService
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockContacts);

        $response = $this->controller->search($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertCount(2, $data['data']);
    }

    public function testSearchContactsReturns400WithMissingQuery(): void
    {
        $request = Request::create('/api/crm/contacts/search', 'GET', []);

        $response = $this->controller->search($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('query', strtolower($data['message']));
    }

    public function testSearchContactsReturns400WithShortQuery(): void
    {
        $request = Request::create('/api/crm/contacts/search', 'GET', [
            'q' => 'ab',
        ]);

        $response = $this->controller->search($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('query', strtolower($data['message']));
    }

    public function testSearchContactsReturns400WithOversizedPage(): void
    {
        $request = Request::create('/api/crm/contacts/search', 'GET', [
            'q' => 'John',
            'page' => 10000,
        ]);

        $response = $this->controller->search($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('page', strtolower($data['message']));
    }

    public function testGetContactReturns200WhenFound(): void
    {
        $request = Request::create('/api/crm/contacts/1', 'GET');

        $mockContact = [
            'id' => 1,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+1-555-123-4567',
            'company' => 'Acme Corp',
            'tags' => ['vip', 'enterprise'],
            'created_at' => '2024-01-15T10:00:00Z',
        ];

        $this->mockContactService
            ->shouldReceive('getById')
            ->with(1)
            ->once()
            ->andReturn($mockContact);

        $response = $this->controller->show($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('John', $data['data']['first_name']);
    }

    public function testGetContactReturns404WhenNotFound(): void
    {
        $request = Request::create('/api/crm/contacts/999', 'GET');

        $this->mockContactService
            ->shouldReceive('getById')
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

    public function testCreateContactReturns201WithValidData(): void
    {
        $request = Request::create('/api/crm/contacts', 'POST', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '+1-555-987-6543',
            'company' => 'Tech Inc',
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $mockCreatedContact = [
            'id' => 10,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'created_at' => '2024-01-20T14:30:00Z',
        ];

        $this->mockContactService
            ->shouldReceive('create')
            ->once()
            ->andReturn($mockCreatedContact);

        $response = $this->controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('Jane', $data['data']['first_name']);
    }

    public function testCreateContactReturns422WithMissingFirstName(): void
    {
        $request = Request::create('/api/crm/contacts', 'POST', [
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('first_name', $data['errors']);
    }

    public function testCreateContactReturns422WithInvalidEmail(): void
    {
        $request = Request::create('/api/crm/contacts', 'POST', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'not-an-email',
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('email', $data['errors']);
    }

    public function testCreateContactReturns422WithDuplicateEmail(): void
    {
        $request = Request::create('/api/crm/contacts', 'POST', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'existing@example.com',
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->mockContactService
            ->shouldReceive('create')
            ->once()
            ->andThrow(new \RuntimeException('Email already exists'));

        $response = $this->controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('email', $data['errors']);
    }

    public function testUpdateContactReturns200WhenSuccessful(): void
    {
        $request = Request::create('/api/crm/contacts/1', 'PUT', [
            'phone' => '+1-555-111-2222',
            'tags' => ['vip', 'premium'],
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $mockUpdatedContact = [
            'id' => 1,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+1-555-111-2222',
            'tags' => ['vip', 'premium'],
        ];

        $this->mockContactService
            ->shouldReceive('update')
            ->with(1, Mockery::type('array'))
            ->once()
            ->andReturn($mockUpdatedContact);

        $response = $this->controller->update($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('+1-555-111-2222', $data['data']['phone']);
    }

    public function testUpdateContactReturns404WhenNotFound(): void
    {
        $request = Request::create('/api/crm/contacts/999', 'PUT', [
            'phone' => '+1-555-111-2222',
        ], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->mockContactService
            ->shouldReceive('update')
            ->with(999, Mockery::type('array'))
            ->once()
            ->andReturn(null);

        $response = $this->controller->update($request, 999);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testDeleteContactReturns204WhenSuccessful(): void
    {
        $request = Request::create('/api/crm/contacts/1', 'DELETE');

        $this->mockContactService
            ->shouldReceive('delete')
            ->with(1)
            ->once()
            ->andReturn(true);

        $response = $this->controller->destroy($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testDeleteContactReturns500WithActiveRelationships(): void
    {
        $request = Request::create('/api/crm/contacts/1', 'DELETE');

        $this->mockContactService
            ->shouldReceive('delete')
            ->with(1)
            ->once()
            ->andThrow(new \RuntimeException('Contact has active deals'));

        $response = $this->controller->destroy($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }
}
