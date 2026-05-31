<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use App\Http\Controllers\Api\UserController;
use App\Http\Requests\CreateUserRequest;
use App\Http\Resources\UserResource;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Mockery;

class UserControllerTest extends TestCase
{
    private UserController $controller;
    private $mockUserService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUserService = Mockery::mock(UserService::class);
        $this->controller = new UserController($this->mockUserService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function assertJsonResponse(JsonResponse $response, int $expectedStatus, array $expectedData = []): void
    {
        $this->assertEquals($expectedStatus, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);

        if (!empty($expectedData)) {
            foreach ($expectedData as $key => $value) {
                $this->assertArrayHasKey($key, $content);
                $this->assertEquals($value, $content[$key]);
            }
        }
    }

    private function assertPaginatedJsonResponse(JsonResponse $response, int $expectedStatus, array $expectedPagination = []): void
    {
        $this->assertEquals($expectedStatus, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey('data', $content);
        $this->assertArrayHasKey('meta', $content);
        $this->assertArrayHasKey('links', $content);

        if (!empty($expectedPagination)) {
            $this->assertEquals($expectedPagination['total'] ?? null, $content['meta']['total'] ?? null);
            $this->assertEquals($expectedPagination['per_page'] ?? null, $content['meta']['per_page'] ?? null);
            $this->assertEquals($expectedPagination['current_page'] ?? null, $content['meta']['current_page'] ?? null);
        }
    }

    private function assertErrorJsonResponse(JsonResponse $response, int $expectedStatus, string $expectedError): void
    {
        $this->assertEquals($expectedStatus, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals($expectedError, $content['error']);
    }

    public function testIndexReturnsPaginatedUsers(): void
    {
        $paginatedUsers = new \Illuminate\Pagination\LengthAwarePaginator(
            items: [
                ['id' => 1, 'email' => 'user1@example.com', 'name' => 'User One'],
                ['id' => 2, 'email' => 'user2@example.com', 'name' => 'User Two'],
            ],
            total: 50,
            perPage: 15,
            currentPage: 1
        );

        $this->mockUserService
            ->shouldReceive('getPaginatedUsers')
            ->with(15, 1, [])
            ->andReturn($paginatedUsers);

        $response = $this->controller->index(new \Illuminate\Http\Request());

        $this->assertPaginatedJsonResponse($response, 200, [
            'total' => 50,
            'per_page' => 15,
            'current_page' => 1,
        ]);

        $content = json_decode($response->getContent(), true);
        $this->assertCount(2, $content['data']);
    }

    public function testShowReturnsUserById(): void
    {
        $user = ['id' => 1, 'email' => 'user@example.com', 'name' => 'Test User'];

        $this->mockUserService
            ->shouldReceive('findById')
            ->with(1)
            ->andReturn($user);

        $response = $this->controller->show(1);

        $this->assertJsonResponse($response, 200, ['email' => 'user@example.com']);
    }

    public function testStoreCreatesNewUser(): void
    {
        $newUser = ['id' => 10, 'email' => 'new@example.com', 'name' => 'New User'];
        $requestData = ['email' => 'new@example.com', 'name' => 'New User', 'password' => 'pass123'];

        $this->mockUserService
            ->shouldReceive('createUser')
            ->with(Mockery::type(CreateUserRequest::class))
            ->andReturn($newUser);

        $request = new CreateUserRequest();
        $request->replace($requestData);

        $response = $this->controller->store($request);

        $this->assertJsonResponse($response, 201, ['id' => 10]);
    }

    public function testDestroyRemovesUser(): void
    {
        $this->mockUserService
            ->shouldReceive('deleteUser')
            ->with(1)
            ->andReturn(true);

        $response = $this->controller->destroy(1);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testShowReturns404ForMissingUser(): void
    {
        $this->mockUserService
            ->shouldReceive('findById')
            ->with(999)
            ->andReturn(null);

        $response = $this->controller->show(999);

        $this->assertErrorJsonResponse($response, 404, 'User not found');
    }
}
