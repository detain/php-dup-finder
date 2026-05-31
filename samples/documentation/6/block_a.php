<?php

declare(strict_types=1);

namespace App\Api\Controllers\Product;

use App\Application\DTOs\Product\ProductSearchRequest;
use App\Application\Services\ProductSearchService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * Product search and filtering API endpoints.
 * Handles product discovery, filtering, sorting, and faceted search.
 *
 * @Route("/api/v1/products", name="api_v1_products_")
 */
class ProductSearchController
{
    public function __construct(
        private readonly ProductSearchService $searchService,
    ) {}

    /**
     * Search products with filters, sorting, and pagination.
     *
     * REQUEST PARAMETERS:
     * - query (string, optional): Full-text search query, searches name/description
     * - category (string, optional): Category slug for filtering
     * - brand (string[], optional): Array of brand slugs to include
     * - min_price (float, optional): Minimum price filter (inclusive)
     * - max_price (float, optional): Maximum price filter (inclusive)
     * - min_rating (float, optional): Minimum average rating (1-5)
     * - in_stock (bool, optional): Filter to only in-stock items
     * - sort_by (string, optional): Sort field - relevance, price_asc, price_desc, rating, newest
     * - page (int, optional): Page number for pagination (default: 1)
     * - per_page (int, optional): Results per page, max 100 (default: 20)
     * - attributes (object, optional): Dynamic attribute filters {color: ["red", "blue"], size: ["M", "L"]}
     *
     * RESPONSE FORMAT:
     * - products (array): Array of product objects with full details
     * - total (int): Total number of matching products
     * - page (int): Current page number
     * - per_page (int): Results per page
     * - total_pages (int): Total number of pages
     * - facets (object): Available filter options with counts
     *   - categories (array): Available categories with product counts
     *   - brands (array): Available brands with product counts
     *   - price_ranges (array): Predefined price ranges with counts
     *   - attributes (object): Attribute values with counts
     *
     * ERROR RESPONSES:
     * - 400 Bad Request: Invalid filter parameters
     * - 422 Unprocessable Entity: Validation error in parameters
     *
     * DOCUMENTED IN:
     * - OpenAPI spec: paths./products.search
     * - Developer portal: /docs/api/products/search
     * - API cookbook: recipes/product-search
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $searchRequest = ProductSearchRequest::fromHttpQuery($request->query->all());

        $result = $this->searchService->search($searchRequest);

        return new JsonResponse([
            'products' => array_map(fn($p) => $p->toArray(), $result->getProducts()),
            'total' => $result->getTotal(),
            'page' => $searchRequest->getPage(),
            'per_page' => $searchRequest->getPerPage(),
            'total_pages' => (int) ceil($result->getTotal() / $searchRequest->getPerPage()),
            'facets' => $result->getFacets()->toArray(),
        ]);
    }

    /**
     * Get product suggestions for autocomplete.
     *
     * REQUEST PARAMETERS:
     * - q (string, required): Search prefix, minimum 2 characters
     * - limit (int, optional): Maximum suggestions to return, max 10 (default: 5)
     * - type (string[], optional): Filter to specific product types
     *
     * RESPONSE FORMAT:
     * - suggestions (array): Array of suggestion objects
     *   - id (string): Product ID
     *   - name (string): Product display name
     *   - category (string): Product category
     *   - price (float): Current price
     *   - image_url (string): Thumbnail image URL
     *
     * ERROR RESPONSES:
     * - 400 Bad Request: Query too short (< 2 characters)
     */
    #[Route('/suggest', name: 'suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $query = $request->query->getString('q', '');
        $limit = $request->query->getInt('limit', 5);

        if (strlen($query) < 2) {
            return new JsonResponse([
                'error' => 'invalid_query',
                'message' => 'Search query must be at least 2 characters',
            ], 400);
        }

        $suggestions = $this->searchService->getSuggestions($query, $limit);

        return new JsonResponse([
            'suggestions' => array_map(fn($s) => $s->toArray(), $suggestions),
        ]);
    }
}
