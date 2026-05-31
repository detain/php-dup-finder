<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Exception\ImportException;
use Psr\Log\LoggerInterface;

final class UserImporter
{
    private const REQUIRED_FIELDS = ['email', 'username', 'password'];

    public function __construct(
        private readonly UserRepository $userRepository,
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

        $this->logger->info('User import completed', [
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

        $this->logger->info('User import completed', [
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

        foreach ($xml->user as $index => $userElement) {
            $data = [
                'email' => (string) $userElement->email,
                'username' => (string) $userElement->username,
                'password' => (string) $userElement->password,
                'full_name' => (string) ($userElement->fullName ?? ''),
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

        $this->logger->info('User import completed', [
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

        $existingUser = $this->userRepository->findByEmail($data['email']);
        if ($existingUser !== null) {
            throw new ImportException('User with email already exists');
        }

        $user = new User(
            $data['email'],
            $data['username'],
            $data['password'],
            $data['full_name'] ?? null
        );

        $this->userRepository->save($user);
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

        if (!empty($data['username']) && strlen($data['username']) < 3) {
            $errors[] = 'Username must be at least 3 characters';
        }

        return $errors;
    }
}
