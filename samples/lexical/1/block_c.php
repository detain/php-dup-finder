<?php
declare(strict_types=1);

namespace Acme\Catalog\Product;

use Acme\Catalog\Product\Dto\ProductCardDto;
use Acme\Catalog\Product\Exception\ProductUnavailableException;
use Acme\Catalog\Product\Source\ProductSource;
use Acme\Telemetry\Tracer;

final class ProductLookupService
{
    public function __construct(
        private readonly ProductSource $source,
        private readonly Tracer $tracer,
    ) {
    }

    public function card(string $sku): ProductCardDto
    {
        $span = $this->tracer->startSpan('product.card.lookup');

        // same token-shape: fetch by id, null-guard throw, build Dto
        $product = $this->source->findById($sku);
        if ($product === null) {
            throw new ProductUnavailableException("product unavailable for sku {$sku}");
        }
        $dto = new ProductCardDto(
            $product->getSku(),
            $product->getDisplayName(),
            $product->getPrice(),
            $product->getUpdatedAt(),
        );

        $span->finish();
        return $dto;
    }

    public function isAvailable(string $sku): bool
    {
        return $this->source->findById($sku) !== null;
    }
}
