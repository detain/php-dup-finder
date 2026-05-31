<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\CsvExporter;
use App\Service\JsonExporter;
use App\Service\XmlExporter;
use Psr\Log\LoggerInterface;

final class OrderExporter
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly CsvExporter $csvExporter,
        private readonly JsonExporter $jsonExporter,
        private readonly XmlExporter $xmlExporter,
        private readonly LoggerInterface $logger,
    ) {}

    public function exportToCsv(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $orders = $this->orderRepository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());

        $data = array_map(fn(Order $order) => [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'customer_email' => $order->getCustomer()->getEmail(),
            'total' => $order->getTotal(),
            'status' => $order->getStatus(),
            'created_at' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $order->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $orders);

        $result = $this->csvExporter->export($data, $filename);

        $this->logger->info('Orders exported to CSV', [
            'filename' => $filename,
            'count' => count($orders),
        ]);

        return count($orders);
    }

    public function exportToJson(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $orders = $this->orderRepository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());

        $data = array_map(fn(Order $order) => [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'customer_email' => $order->getCustomer()->getEmail(),
            'total' => $order->getTotal(),
            'status' => $order->getStatus(),
            'created_at' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $order->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $orders);

        $result = $this->jsonExporter->export($data, $filename);

        $this->logger->info('Orders exported to JSON', [
            'filename' => $filename,
            'count' => count($orders),
        ]);

        return count($orders);
    }

    public function exportToXml(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $orders = $this->orderRepository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());

        $data = array_map(fn(Order $order) => [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'customer_email' => $order->getCustomer()->getEmail(),
            'total' => $order->getTotal(),
            'status' => $order->getStatus(),
            'created_at' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $order->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $orders);

        $result = $this->xmlExporter->export(['order' => $data], $filename);

        $this->logger->info('Orders exported to XML', [
            'filename' => $filename,
            'count' => count($orders),
        ]);

        return count($orders);
    }

    public function exportFilteredToCsv(string $filename, array $filters): int
    {
        $orders = $this->orderRepository->findByFilters($filters);

        $data = array_map(fn(Order $order) => [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'customer_email' => $order->getCustomer()->getEmail(),
            'total' => $order->getTotal(),
            'status' => $order->getStatus(),
            'created_at' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $order->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $orders);

        $result = $this->csvExporter->export($data, $filename);

        $this->logger->info('Filtered orders exported to CSV', [
            'filename' => $filename,
            'count' => count($orders),
            'filters' => $filters,
        ]);

        return count($orders);
    }

    public function exportFilteredToJson(string $filename, array $filters): int
    {
        $orders = $this->orderRepository->findByFilters($filters);

        $data = array_map(fn(Order $order) => [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'customer_email' => $order->getCustomer()->getEmail(),
            'total' => $order->getTotal(),
            'status' => $order->getStatus(),
            'created_at' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $order->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $orders);

        $result = $this->jsonExporter->export($data, $filename);

        $this->logger->info('Filtered orders exported to JSON', [
            'filename' => $filename,
            'count' => count($orders),
            'filters' => $filters,
        ]);

        return count($orders);
    }

    public function exportFilteredToXml(string $filename, array $filters): int
    {
        $orders = $this->orderRepository->findByFilters($filters);

        $data = array_map(fn(Order $order) => [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
            'customer_email' => $order->getCustomer()->getEmail(),
            'total' => $order->getTotal(),
            'status' => $order->getStatus(),
            'created_at' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $order->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $orders);

        $result = $this->xmlExporter->export(['order' => $data], $filename);

        $this->logger->info('Filtered orders exported to XML', [
            'filename' => $filename,
            'count' => count($orders),
            'filters' => $filters,
        ]);

        return count($orders);
    }
}
