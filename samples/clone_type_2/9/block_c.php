<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Service\CsvExporter;
use App\Service\JsonExporter;
use App\Service\XmlExporter;
use Psr\Log\LoggerInterface;

final class CustomerExporter
{
    public function __construct(
        private readonly CustomerRepository $customerRepository,
        private readonly CsvExporter $csvExporter,
        private readonly JsonExporter $jsonExporter,
        private readonly XmlExporter $xmlExporter,
        private readonly LoggerInterface $logger,
    ) {}

    public function exportToCsv(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $customers = $this->customerRepository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());

        $data = array_map(fn(Customer $customer) => [
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'company_name' => $customer->getCompanyName(),
            'credit_limit' => $customer->getCreditLimit(),
            'status' => $customer->getStatus(),
            'created_at' => $customer->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $customer->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $customers);

        $result = $this->csvExporter->export($data, $filename);

        $this->logger->info('Customers exported to CSV', [
            'filename' => $filename,
            'count' => count($customers),
        ]);

        return count($customers);
    }

    public function exportToJson(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $customers = $this->customerRepository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());

        $data = array_map(fn(Customer $customer) => [
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'company_name' => $customer->getCompanyName(),
            'credit_limit' => $customer->getCreditLimit(),
            'status' => $customer->getStatus(),
            'created_at' => $customer->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $customer->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $customers);

        $result = $this->jsonExporter->export($data, $filename);

        $this->logger->info('Customers exported to JSON', [
            'filename' => $filename,
            'count' => count($customers),
        ]);

        return count($customers);
    }

    public function exportToXml(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $customers = $this->customerRepository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());

        $data = array_map(fn(Customer $customer) => [
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'company_name' => $customer->getCompanyName(),
            'credit_limit' => $customer->getCreditLimit(),
            'status' => $customer->getStatus(),
            'created_at' => $customer->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $customer->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $customers);

        $result = $this->xmlExporter->export(['customer' => $data], $filename);

        $this->logger->info('Customers exported to XML', [
            'filename' => $filename,
            'count' => count($customers),
        ]);

        return count($customers);
    }

    public function exportFilteredToCsv(string $filename, array $filters): int
    {
        $customers = $this->customerRepository->findByFilters($filters);

        $data = array_map(fn(Customer $customer) => [
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'company_name' => $customer->getCompanyName(),
            'credit_limit' => $customer->getCreditLimit(),
            'status' => $customer->getStatus(),
            'created_at' => $customer->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $customer->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $customers);

        $result = $this->csvExporter->export($data, $filename);

        $this->logger->info('Filtered customers exported to CSV', [
            'filename' => $filename,
            'count' => count($customers),
            'filters' => $filters,
        ]);

        return count($customers);
    }

    public function exportFilteredToJson(string $filename, array $filters): int
    {
        $customers = $this->customerRepository->findByFilters($filters);

        $data = array_map(fn(Customer $customer) => [
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'company_name' => $customer->getCompanyName(),
            'credit_limit' => $customer->getCreditLimit(),
            'status' => $customer->getStatus(),
            'created_at' => $customer->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $customer->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $customers);

        $result = $this->jsonExporter->export($data, $filename);

        $this->logger->info('Filtered customers exported to JSON', [
            'filename' => $filename,
            'count' => count($customers),
            'filters' => $filters,
        ]);

        return count($customers);
    }

    public function exportFilteredToXml(string $filename, array $filters): int
    {
        $customers = $this->customerRepository->findByFilters($filters);

        $data = array_map(fn(Customer $customer) => [
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'company_name' => $customer->getCompanyName(),
            'credit_limit' => $customer->getCreditLimit(),
            'status' => $customer->getStatus(),
            'created_at' => $customer->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $customer->getUpdatedAt()->format('Y-m-d H:i:s'),
        ], $customers);

        $result = $this->xmlExporter->export(['customer' => $data], $filename);

        $this->logger->info('Filtered customers exported to XML', [
            'filename' => $filename,
            'count' => count($customers),
            'filters' => $filters,
        ]);

        return count($customers);
    }
}
