<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\CsvExporter;
use App\Service\JsonExporter;
use App\Service\XmlExporter;
use Psr\Log\LoggerInterface;

final class ProductExporter
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly CsvExporter $csvExporter,
        private readonly JsonExporter $jsonExporter,
        private readonly XmlExporter $xmlExporter,
        private readonly LoggerInterface $logger,
    ) {}

    public function exportToCsv(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $products = $this->productRepository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());

        $data = array_map(fn(Product $product) => [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'stock' => $product->getStock(),
            'status' => $product->getStatus(),
            'created_at' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $product->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $products);

        $result = $this->csvExporter->export($data, $filename);

        $this->logger->info('Products exported to CSV', [
            'filename' => $filename,
            'count' => count($products),
        ]);

        return count($products);
    }

    public function exportToJson(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $products = $this->productRepository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());

        $data = array_map(fn(Product $product) => [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'stock' => $product->getStock(),
            'status' => $product->getStatus(),
            'created_at' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $product->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $products);

        $result = $this->jsonExporter->export($data, $filename);

        $this->logger->info('Products exported to JSON', [
            'filename' => $filename,
            'count' => count($products),
        ]);

        return count($products);
    }

    public function exportToXml(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $products = $this->productRepository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());

        $data = array_map(fn(Product $product) => [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'stock' => $product->getStock(),
            'status' => $product->getStatus(),
            'created_at' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $product->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $products);

        $result = $this->xmlExporter->export(['product' => $data], $filename);

        $this->logger->info('Products exported to XML', [
            'filename' => $filename,
            'count' => count($products),
        ]);

        return count($products);
    }

    public function exportFilteredToCsv(string $filename, array $filters): int
    {
        $products = $this->productRepository->findByFilters($filters);

        $data = array_map(fn(Product $product) => [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'stock' => $product->getStock(),
            'status' => $product->getStatus(),
            'created_at' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $product->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $products);

        $result = $this->csvExporter->export($data, $filename);

        $this->logger->info('Filtered products exported to CSV', [
            'filename' => $filename,
            'count' => count($products),
            'filters' => $filters,
        ]);

        return count($products);
    }

    public function exportFilteredToJson(string $filename, array $filters): int
    {
        $products = $this->productRepository->findByFilters($filters);

        $data = array_map(fn(Product $product) => [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'stock' => $product->getStock(),
            'status' => $product->getStatus(),
            'created_at' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $product->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $products);

        $result = $this->jsonExporter->export($data, $filename);

        $this->logger->info('Filtered products exported to JSON', [
            'filename' => $filename,
            'count' => count($products),
            'filters' => $filters,
        ]);

        return count($products);
    }

    public function exportFilteredToXml(string $filename, array $filters): int
    {
        $products = $this->productRepository->findByFilters($filters);

        $data = array_map(fn(Product $product) => [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'stock' => $product->getStock(),
            'status' => $product->getStatus(),
            'created_at' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $product->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $products);

        $result = $this->xmlExporter->export(['product' => $data], $filename);

        $this->logger->info('Filtered products exported to XML', [
            'filename' => $filename,
            'count' => count($products),
            'filters' => $filters,
        ]);

        return count($products);
    }
}
