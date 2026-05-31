<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportableInterface;
use App\Repository\ImportableRepositoryInterface;
use App\Exception\ImportException;
use Psr\Log\LoggerInterface;

interface ImporterInterface
{
    public function importFromCsv(string $filename): ImportResult;
    public function importFromJson(string $filename): ImportResult;
    public function importFromXml(string $filename): ImportResult;
}

abstract class AbstractImporter implements ImporterInterface
{
    protected const REQUIRED_FIELDS = [];

    public function __construct(
        protected readonly ImportableRepositoryInterface $repository,
        protected readonly LoggerInterface $logger,
    ) {}

    public function importFromCsv(string $filename): ImportResult
    {
        $handle = fopen($filename, 'r');
        $headers = fgetcsv($handle);

        if ($headers === false) {
            throw new ImportException('Unable to read CSV headers');
        }

        $imported = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $rowNumber = $imported + count($errors) + 2;

            try {
                $this->processRow($data);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = $this->createError($rowNumber, $e->getMessage(), $data);
            }
        }

        fclose($handle);

        return $this->createResult($filename, $imported, $errors);
    }

    public function importFromJson(string $filename): ImportResult
    {
        $content = file_get_contents($filename);
        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new ImportException('Invalid JSON format');
        }

        $imported = 0;
        $errors = [];

        foreach ($data as $index => $item) {
            try {
                $this->processRow($item);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = $this->createError($index + 1, $e->getMessage(), $item);
            }
        }

        return $this->createResult($filename, $imported, $errors);
    }

    public function importFromXml(string $filename): ImportResult
    {
        $xml = simplexml_load_file($filename);

        if ($xml === false) {
            throw new ImportException('Invalid XML format');
        }

        $imported = 0;
        $errors = [];
        $elementName = $this->getXmlElementName();

        foreach ($xml->$elementName as $index => $element) {
            $data = $this->xmlToArray($element);

            try {
                $this->processRow($data);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = $this->createError($index + 1, $e->getMessage(), $data);
            }
        }

        return $this->createResult($filename, $imported, $errors);
    }

    protected function processRow(array $data): void
    {
        $errors = $this->validate($data);

        if (!empty($errors)) {
            throw new ImportException('Validation failed: ' . implode(', ', $errors));
        }

        $this->checkForDuplicate($data);
        $entity = $this->createEntity($data);
        $this->repository->save($entity);
    }

    protected function validate(array $data): array
    {
        $errors = [];

        foreach (static::REQUIRED_FIELDS as $field) {
            if (empty($data[$field]) && $data[$field] !== '0') {
                $errors[] = "Field '{$field}' is required";
            }
        }

        return $errors;
    }

    abstract protected function getXmlElementName(): string;
    abstract protected function xmlToArray(\SimpleXMLElement $element): array;
    abstract protected function checkForDuplicate(array $data): void;
    abstract protected function createEntity(array $data): ImportableInterface;
    abstract protected function createError(int $row, string $message, array $data): array;
    abstract protected function createResult(string $filename, int $imported, array $errors): ImportResult;
}
