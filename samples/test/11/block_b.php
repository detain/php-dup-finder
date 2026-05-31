<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Http\Controllers\Api\v1\ProductController;
use App\Http\RequestValidators\ProductRequestValidator;
use App\Http\Resources\ProductResource;
use App\Services\ProductService;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductApiControllerTest extends TestCase
{
    private ProductController $controller;
    private MockObject&ProductService $productService;
    private MockObject&ProductRequestValidator $validator;

    protected function setUp(): void
    {
        $this->productService = $this->createMock(ProductService::class);
        $this->validator = $this->createMock(ProductRequestValidator::class);

        $this->controller = new ProductController(
            $this->productService,
            $this->validator
        );

        $this->setupValidatorMockBehavior();
        $this->setupServiceMockBehavior();
    }

    private function setupValidatorMockBehavior(): void
    {
        $this->validator->method('validateIndexRequest')
            ->willReturnCallback(function (Request $request) {
                $errors = [];

                if (!$request->has('page') || !is_numeric($request->get('page'))) {
                    $errors['page'] = 'Page must be a valid number';
                }

                if (!$request->has('per_page') || !is_numeric($request->get('per_page'))) {
                    $errors['per_page'] = 'Per page must be a valid number';
                } elseif ((int) $request->get('per_page') > 100) {
                    $errors['per_page'] = 'Per page cannot exceed 100';
                }

                if (empty($errors)) {
                    return true;
                }

                throw new ValidationException('Validation failed', $errors);
            });

        $this->validator->method('validateStoreRequest')
            ->willReturnCallback(function (Request $request) {
                $errors = [];

                if (!$request->has('name') || empty($request->get('name'))) {
                    $errors['name'] = 'Name is required';
                } elseif (strlen($request->get('name')) < 3) {
                    $errors['name'] = 'Name must be at least 3 characters';
                } elseif (strlen($request->get('name')) > 255) {
                    $errors['name'] = 'Name cannot exceed 255 characters';
                }

                if (!$request->has('price') || !is_numeric($request->get('price'))) {
                    $errors['price'] = 'Price is required and must be numeric';
                } elseif ((float) $request->get('price') <= 0) {
                    $errors['price'] = 'Price must be greater than 0';
                }

                if (!$request->has('category_id') || !is_numeric($request->get('category_id'))) {
                    $errors['category_id'] = 'Category ID is required';
                }

                if (empty($errors)) {
                    return true;
                }

                throw new ValidationException('Validation failed', $errors);
            });

        $this->validator->method('validateUpdateRequest')
            ->willReturnCallback(function (Request $request) {
                $errors = [];

                if ($request->has('name')) {
                    if (strlen($request->get('name')) < 3) {
                        $errors['name'] = 'Name must be at least 3 characters';
                    } elseif (strlen($request->get('name')) > 255) {
                        $errors['name'] = 'Name cannot exceed 255 characters';
                    }
                }

                if ($request->has('price')) {
                    if (!is_numeric($request->get('price'))) {
                        $errors['price'] = 'Price must be numeric';
                    } elseif ((float) $request->get('price') <= 0) {
                        $errors['price'] = 'Price must be greater than 0';
                    }
                }

                if (empty($errors)) {
                    return true;
                }

                throw new ValidationException('Validation failed', $errors);
            });
    }

    private function setupServiceMockBehavior(): void
    {
        $this->productService->method('getAll')
            ->willReturn([
                'data' => [
                    ['id' => 1, 'name' => 'Product 1', 'price' => 99.99],
                    ['id' => 2, 'name' => 'Product 2', 'price' => 149.99],
                ],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 10,
                    'total' => 2,
                ],
            ]);

        $this->productService->method('getById')
            ->willReturnCallback(function (int $id) {
                if ($id === 1) {
                    return ['id' => 1, 'name' => 'Product 1', 'price' => 99.99];
                }
                throw new NotFoundException('Product not found');
            });
    }

    public function testIndexReturnsSuccessfulResponse(): void
    {
        $request = Request::create('/api/v1/products', 'GET', [
            'page' => '1',
            'per_page' => '10',
        ]);

        $response = $this->controller->index($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertCount(2, $data['data']);
    }

    public function testIndexWithInvalidPageParameter(): void
    {
        $request = Request::create('/api/v1/products', 'GET', [
            'page' => 'invalid',
        ]);

        $this->expectException(ValidationException::class);

        $this->controller->index($request);
    }

    public function testIndexWithExcessivePerPage(): void
    {
        $request = Request::create('/api/v1/products', 'GET', [
            'page' => '1',
            'per_page' => '500',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Per page cannot exceed 100');

        $this->controller->index($request);
    }

    public function testShowReturnsSuccessfulResponse(): void
    {
        $request = Request::create('/api/v1/products/1', 'GET');

        $response = $this->controller->show($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Product 1', $data['data']['name']);
    }

    public function testShowWithNonexistentProduct(): void
    {
        $request = Request::create('/api/v1/products/999', 'GET');

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Product not found');

        $this->controller->show($request, 999);
    }

    public function testStoreWithValidData(): void
    {
        $request = Request::create('/api/v1/products', 'POST', [
            'name' => 'New Product',
            'price' => '199.99',
            'category_id' => '1',
        ]);

        $this->productService->method('create')
            ->willReturn([
                'id' => 3,
                'name' => 'New Product',
                'price' => 199.99,
                'category_id' => 1,
            ]);

        $response = $this->controller->store($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('New Product', $data['data']['name']);
    }

    public function testStoreWithMissingName(): void
    {
        $request = Request::create('/api/v1/products', 'POST', [
            'price' => '199.99',
            'category_id' => '1',
        ]);

        $this->expectException(ValidationException::class);

        $this->controller->store($request);
    }

    public function testStoreWithInvalidPrice(): void
    {
        $request = Request::create('/api/v1/products', 'POST', [
            'name' => 'Test Product',
            'price' => '-50',
            'category_id' => '1',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Price must be greater than 0');

        $this->controller->store($request);
    }

    public function testStoreWithShortName(): void
    {
        $request = Request::create('/api/v1/products', 'POST', [
            'name' => 'AB',
            'price' => '199.99',
            'category_id' => '1',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Name must be at least 3 characters');

        $this->controller->store($request);
    }

    public function testUpdateWithValidData(): void
    {
        $request = Request::create('/api/v1/products/1', 'PUT', [
            'name' => 'Updated Product',
            'price' => '299.99',
        ]);

        $this->productService->method('update')
            ->willReturn([
                'id' => 1,
                'name' => 'Updated Product',
                'price' => 299.99,
            ]);

        $response = $this->controller->update($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Updated Product', $data['data']['name']);
    }

    public function testDestroyReturnsNoContent(): void
    {
        $request = Request::create('/api/v1/products/1', 'DELETE');

        $this->productService->method('delete')
            ->willReturn(true);

        $response = $this->controller->destroy($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(204, $response->getStatusCode());
    }
}
