<?php
declare(strict_types=1);

namespace App\Api\Controllers\V1;

use App\Services\ProductService;
use App\Logging\LoggerInterface;
use App\Exceptions\ApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ProductController
{
    private ProductService $productService;
    private LoggerInterface $logger;
    private array $supportedVersions = ['1.0', '1.1', '2.0'];
    private string $currentVersion = '2.0';

    public function __construct(
        ProductService $productService,
        LoggerInterface $logger
    ) {
        $this->productService = $productService;
        $this->logger = $logger;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $apiVersion = $this->extractVersionFromHeaders($request->headers);
            
            if (!$this->isVersionSupported($apiVersion)) {
                return $this->createVersionErrorResponse($apiVersion);
            }
            
            $contentType = $this->negotiateContentType($request);
            $acceptLanguage = $request->headers->get('Accept-Language', 'en');
            
            $products = $this->productService->getAllProducts([
                'page' => (int)$request->query->get('page', 1),
                'limit' => (int)$request->query->get('limit', 20),
                'category' => $request->query->get('category'),
            ]);
            
            $this->logger->info('Products retrieved successfully', [
                'count' => count($products),
                'version' => $apiVersion,
            ]);
            
            return new JsonResponse([
                'data' => $products,
                'meta' => [
                    'api_version' => $apiVersion,
                    'content_type' => $contentType,
                ],
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve products', [
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $apiVersion = $this->extractVersionFromHeaders($request->headers);
            
            if (!$this->isVersionSupported($apiVersion)) {
                return $this->createVersionErrorResponse($apiVersion);
            }
            
            $product = $this->productService->getProductById($id);
            
            if ($product === null) {
                return new JsonResponse(['error' => 'Product not found'], 404);
            }
            
            $this->logger->info('Product retrieved', ['id' => $id]);
            
            return new JsonResponse([
                'data' => $product,
                'meta' => ['api_version' => $apiVersion],
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve product', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    private function extractVersionFromHeaders($headers): string
    {
        $apiVersion = $headers->get('API-Version') ?? 
                      $headers->get('Api-Version') ?? 
                      $headers->get('api-version') ?? 
                      $this->currentVersion;
        
        return trim($apiVersion);
    }

    private function isVersionSupported(string $version): bool
    {
        return in_array($version, $this->supportedVersions, true);
    }

    private function negotiateContentType(Request $request): string
    {
        $accept = $request->headers->get('Accept', 'application/json');
        
        if (str_contains($accept, 'application/xml')) {
            return 'application/xml';
        }
        
        return 'application/json';
    }

    private function createVersionErrorResponse(string $version): JsonResponse
    {
        $this->logger->warning('Unsupported API version requested', [
            'version' => $version,
            'supported' => $this->supportedVersions,
        ]);
        
        return new JsonResponse([
            'error' => 'Unsupported API version',
            'requested_version' => $version,
            'supported_versions' => $this->supportedVersions,
        ], 406);
    }
}
