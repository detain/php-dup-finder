<?php

declare(strict_types=1);

namespace App\Api\Controllers\Product;

use App\Application\DTOs\Product\CreateProductRequest;
use App\Application\DTOs\Product\UpdateProductRequest;
use App\Application\Services\ProductService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Repository\ProductRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * Product catalog management API endpoints.
 * Handles product creation, updates, inventory tracking, pricing,
 * and search functionality for the e-commerce platform.
 *
 * @Route("/api/v1/products", name="api_v1_products_")
 * @OA\Tag(name="Products", description="Product catalog and inventory management")
 */
class ProductController
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create a new product in the catalog.
     *
     * @param CreateProductRequest $request The product creation payload
     *   - sku: string (required) Unique Stock Keeping Unit identifier
     *   - name: string (required) Product display name, 3-200 characters
     *   - description: string (required) Full product description, 10-5000 characters
     *   - price: float (required) Base price in USD, must be positive
     *   - currency: string (required) ISO 4217 currency code (USD, EUR, GBP)
     *   - categoryId: string (required) UUID of product category
     *   - inventory: int (required) Initial stock quantity, non-negative
     *   - lowStockThreshold: int (optional, default 10) Alert threshold for inventory
     *   - images: array (optional) Array of image URLs, max 8 images
     *   - attributes: array (optional) Key-value pairs for variant attributes
     *   - isActive: bool (optional, default true) Whether product is visible
     * @return JsonResponse 201 with productId and created timestamp
     *   - productId: string UUID of created product
     *   - sku: string The assigned SKU
     *   - createdAt: string ISO 8601 timestamp
     * @throws ValidationException 422 if SKU already exists or validation fails
     * @throws DomainException 400 if categoryId doesn't exist
     *
     * @OA\Post(
     *   path="/api/v1/products",
     *   summary="Create a new product",
     *   tags={"Products"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"sku", "name", "description", "price", "currency", "categoryId", "inventory"},
     *       @OA\Property(property="sku", type="string", example="PROD-WIDGET-001"),
     *       @OA\Property(property="name", type="string", example="Premium Widget"),
     *       @OA\Property(property="description", type="string", example="A high-quality widget for all your widget needs"),
     *       @OA\Property(property="price", type="number", format="float", example=29.99),
     *       @OA\Property(property="currency", type="string", enum={"USD","EUR","GBP"}, example="USD"),
     *       @OA\Property(property="categoryId", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
     *       @OA\Property(property="inventory", type="integer", minimum=0, example=100),
     *       @OA\Property(property="lowStockThreshold", type="integer", example=10),
     *       @OA\Property(property="images", type="array", @OA\Items(type="string"), example={"https://example.com/img1.jpg"}),
     *       @OA\Property(property="attributes", type="object", example={"color": "blue", "size": "large"}),
     *       @OA\Property(property="isActive", type="boolean", example=true)
     *     )
     *   ),
     *   @OA\Response(response=201, description="Product created successfully"),
     *   @OA\Response(response=422, description="Validation error - duplicate SKU"),
     *   @OA\Response(response=400, description="Invalid category")
     * )
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(CreateProductRequest $request): JsonResponse
    {
        $this->logger->info('Creating new product', [
            'sku' => $request->getSku(),
            'category_id' => $request->getCategoryId(),
        ]);

        try {
            $product = $this->productService->createProduct($request->toDomainCommand());

            $this->logger->info('Product created successfully', [
                'product_id' => $product->getId()->toString(),
                'sku' => $product->getSku(),
            ]);

            return new JsonResponse([
                'productId' => $product->getId()->toString(),
                'sku' => $product->getSku(),
                'createdAt' => $product->getCreatedAt()->format(\DateTimeImmutable::ATOM),
            ], Response::HTTP_CREATED);

        } catch (SkuAlreadyExistsException $e) {
            $this->logger->warning('Product creation failed - duplicate SKU', [
                'sku' => $request->getSku(),
            ]);
            return new JsonResponse([
                'error' => 'sku_already_exists',
                'message' => 'A product with this SKU already exists',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (CategoryNotFoundException $e) {
            $this->logger->error('Product creation failed - category not found', [
                'category_id' => $request->getCategoryId(),
            ]);
            return new JsonResponse([
                'error' => 'category_not_found',
                'message' => 'The specified category does not exist',
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
