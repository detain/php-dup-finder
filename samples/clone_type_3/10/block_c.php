<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Exception\ImportException;
use Psr\Log\LoggerInterface;

final class CustomerImporter
{
    private const REQUIRED_FIELDS = ['email', 'company_name'];

    public function __construct(
        private readonly CustomerRepository $customerRepository,
        private readonly LoggerInterface $logger,
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

            try {
                $this->validateAndImport($data);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $imported + count($errors) + 2,
                    'error' => $e->getMessage(),
                    'data' => $data,
                ];
            }
        }

        fclose($handle);

        $this->logger->info('Customer import completed', [
            'filename' => $filename,
            'imported' => $imported,
            'errors' => count($errors),
        ]);

        return new ImportResult($imported, count($errors), $errors);
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
                $this->validateAndImport($item);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'error' => $e->getMessage(),
                    'data' => $item,
                ];
            }
        }

        $this->logger->info('Customer import completed', [
            'filename' => $filename,
            'imported' => $imported,
            'errors' => count($errors),
        ]);

        return new ImportResult($imported, count($errors), $errors);
    }

    public function importFromXml(string $filename): ImportResult
    {
        $xml = simplexml_load_file($filename);

        if ($xml === false) {
            throw new ImportException('Invalid XML format');
        }

        $imported = 0;
        $errors = [];

        foreach ($xml->customer as $index => $customerElement) {
            $data = [
                'email' => (string) $customerElement->email,
                'company_name' => (string) $customerElement->companyName,
                'contact_name' => (string) ($customerElement->contactName ?? ''),
                'phone' => (string) ($customerElement->phone ?? ''),
            ];

            try {
                $this->validateAndImport($data);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'error' => $e->getMessage(),
                    'data' => $data,
                ];
            }
        }

        $this->logger->info('Customer import completed', [
            'filename' => $filename,
            'imported' => $imported,
            'errors' => count($errors),
        ]);

        return new ImportResult($imported, count($errors), $errors);
    }

    private function validateAndImport(array $data): void
    {
        $errors = $this->validate($data);

        if (!empty($errors)) {
            throw new ImportException('Validation failed: ' . implode(', ', $errors));
        }

        $existingCustomer = $this->customerRepository->findByEmail($data['email']);
        if ($existingCustomer !== null) {
            throw new ImportException('Customer with email already exists');
        }

        $customer = new Customer(
            $data['email'],
            $data['company_name'],
            $data['contact_name'] ?? null
        );

        $customer->setPhone($data['phone'] ?? null);
        $this->customerRepository->save($customer);
    }

    private function validate(array $data): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        if (!empty($data['phone']) && !preg_match('/^\+?[0-9]{10,15}$/', $data['phone'])) {
            $errors[] = 'Invalid phone format';
        }

        return $errors;
    }
}
