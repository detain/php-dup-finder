<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\ExportableInterface;
use App\Repository\ExportableRepositoryInterface;
use App\Service\CsvExporter;
use App\Service\JsonExporter;
use App\Service\XmlExporter;
use Psr\Log\LoggerInterface;

interface ExporterInterface
{
    public function exportToCsv(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int;
    public function exportToJson(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int;
    public function exportToXml(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int;
    public function exportFilteredToCsv(string $filename, array $filters): int;
    public function exportFilteredToJson(string $filename, array $filters): int;
    public function exportFilteredToXml(string $filename, array $filters): int;
}

abstract class AbstractExporter implements ExporterInterface
{
    public function __construct(
        protected readonly ExportableRepositoryInterface $repository,
        protected readonly CsvExporter $csvExporter,
        protected readonly JsonExporter $jsonExporter,
        protected readonly XmlExporter $xmlExporter,
        protected readonly LoggerInterface $logger,
    ) {}

    public function exportToCsv(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $entities = $this->repository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());
        $data = $this->transformEntities($entities);

        $this->csvExporter->export($data, $filename);

        $this->logger->info('Exported to CSV', [
            'type' => $this->getEntityType(),
            'filename' => $filename,
            'count' => count($entities),
        ]);

        return count($entities);
    }

    public function exportToJson(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $entities = $this->repository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());
        $data = $this->transformEntities($entities);

        $this->jsonExporter->export($data, $filename);

        $this->logger->info('Exported to JSON', [
            'type' => $this->getEntityType(),
            'filename' => $filename,
            'count' => count($entities),
        ]);

        return count($entities);
    }

    public function exportToXml(string $filename, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $entities = $this->repository->findByDateRange($from ?? new \DateTime('-30 days'), $to ?? new \DateTime());
        $data = $this->transformEntities($entities);

        $this->xmlExporter->export([$this->getEntityType() => $data], $filename);

        $this->logger->info('Exported to XML', [
            'type' => $this->getEntityType(),
            'filename' => $filename,
            'count' => count($entities),
        ]);

        return count($entities);
    }

    public function exportFilteredToCsv(string $filename, array $filters): int
    {
        $entities = $this->repository->findByFilters($filters);
        $data = $this->transformEntities($entities);

        $this->csvExporter->export($data, $filename);

        $this->logger->info('Filtered exported to CSV', [
            'type' => $this->getEntityType(),
            'filename' => $filename,
            'count' => count($entities),
            'filters' => $filters,
        ]);

        return count($entities);
    }

    public function exportFilteredToJson(string $filename, array $filters): int
    {
        $entities = $this->repository->findByFilters($filters);
        $data = $this->transformEntities($entities);

        $this->jsonExporter->export($data, $filename);

        $this->logger->info('Filtered exported to JSON', [
            'type' => $this->getEntityType(),
            'filename' => $filename,
            'count' => count($entities),
            'filters' => $filters,
        ]);

        return count($entities);
    }

    public function exportFilteredToXml(string $filename, array $filters): int
    {
        $entities = $this->repository->findByFilters($filters);
        $data = $this->transformEntities($entities);

        $this->xmlExporter->export([$this->getEntityType() => $data], $filename);

        $this->logger->info('Filtered exported to XML', [
            'type' => $this->getEntityType(),
            'filename' => $filename,
            'count' => count($entities),
            'filters' => $filters,
        ]);

        return count($entities);
    }

    abstract protected function getEntityType(): string;
    abstract protected function transformEntities(array $entities): array;
}
