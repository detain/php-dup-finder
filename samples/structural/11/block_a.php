<?php
declare(strict_types=1);

namespace DataImport\Pipeline;

use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UserImportPipeline
{
    private const BATCH_SIZE = 100;
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ValidatorInterface $validator,
        private readonly UserRepository $userRepository,
        private readonly UserEventDispatcher $eventDispatcher,
    ) {}

    public function process(string $filePath): ImportResult
    {
        $this->logger->info('Starting user import pipeline', ['file' => $filePath]);

        $rawRecords = $this->extract($filePath);
        $this->logger->debug('Extracted raw records', ['count' => count($rawRecords)]);

        $validatedRecords = $this->validate($rawRecords);
        $this->logger->debug('Validated records', ['count' => count($validatedRecords)]);

        $transformedRecords = $this->transform($validatedRecords);
        $this->logger->debug('Transformed records', ['count' => count($transformedRecords)]);

        $enrichedRecords = $this->enrich($transformedRecords);
        $this->logger->debug('Enriched records', ['count' => count($enrichedRecords)]);

        $persistedRecords = $this->persist($enrichedRecords);
        $this->logger->info('Persisted records', ['count' => count($persistedRecords)]);

        $this->notify($persistedRecords);
        $this->logger->info('User import pipeline completed');

        return new ImportResult(
            totalRecords: count($rawRecords),
            successfulRecords: count($persistedRecords),
            failedRecords: count($rawRecords) - count($persistedRecords),
            errors: $this->gatherErrors(),
        );
    }

    private function extract(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            throw new \RuntimeException('Cannot read CSV headers');
        }

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            $record = array_combine($headers, $row);
            $records[] = $record;
        }
        fclose($handle);

        return $records;
    }

    private function validate(array $records): array
    {
        $validated = [];
        $errors = [];

        foreach ($records as $index => $record) {
            $userDto = $this->mapToUserDto($record);
            $violations = $this->validator->validate($userDto);

            if (count($violations) > 0) {
                $errorMessages = [];
                foreach ($violations as $violation) {
                    $errorMessages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
                }
                $errors[$index] = $errorMessages;
                continue;
            }

            $validated[$index] = $userDto;
        }

        $this->logger->debug('Validation completed', [
            'valid' => count($validated),
            'invalid' => count($errors),
        ]);

        return $validated;
    }

    private function transform(array $validatedRecords): array
    {
        $transformed = [];

        foreach ($validatedRecords as $index => $userDto) {
            $transformed[$index] = new TransformedUserRecord(
                email: strtolower(trim($userDto->email)),
                firstName: ucwords(strtolower(trim($userDto->firstName))),
                lastName: ucwords(strtolower(trim($userDto->lastName))),
                phone: $this->normalizePhone($userDto->phone),
                createdAt: new \DateTimeImmutable(),
                status: 'pending_verification',
            );
        }

        return $transformed;
    }

    private function enrich(array $transformedRecords): array
    {
        $enriched = [];

        foreach ($transformedRecords as $index => $record) {
            $enriched[$index] = new EnrichedUserRecord(
                original: $record,
                emailDomain: $this->extractEmailDomain($record->email),
                geolocation: $this->inferGeolocation($record),
                userSegment: $this->classifyUserSegment($record),
                emailVerificationToken: bin2hex(random_bytes(16)),
            );
        }

        return $enriched;
    }

    private function persist(array $enrichedRecords): array
    {
        $persisted = [];
        $batches = array_chunk($enrichedRecords, self::BATCH_SIZE, true);

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->debug('Processing batch', [
                'batch' => $batchIndex,
                'size' => count($batch),
            ]);

            foreach ($batch as $index => $record) {
                $attempts = 0;
                $success = false;

                while ($attempts < self::MAX_RETRIES && !$success) {
                    try {
                        $user = new User(
                            email: $record->original->email,
                            firstName: $record->original->firstName,
                            lastName: $record->original->lastName,
                            phone: $record->original->phone,
                            status: $record->original->status,
                            emailDomain: $record->emailDomain,
                            userSegment: $record->userSegment,
                        );

                        $this->userRepository->save($user);
                        $persisted[$index] = $record;
                        $success = true;
                    } catch (\Throwable $e) {
                        $attempts++;
                        $this->logger->warning('Persist attempt failed', [
                            'index' => $index,
                            'attempt' => $attempts,
                            'error' => $e->getMessage(),
                        ]);

                        if ($attempts >= self::MAX_RETRIES) {
                            $this->recordError($index, 'persist_failed: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        return $persisted;
    }

    private function notify(array $persistedRecords): void
    {
        foreach ($persistedRecords as $record) {
            $this->eventDispatcher->dispatch(
                new UserImportedEvent(
                    userId: $record->original->email,
                    email: $record->original->email,
                    segment: $record->userSegment,
                    importedAt: $record->original->createdAt,
                )
            );
        }
    }

    private function mapToUserDto(array $record): UserImportDto
    {
        return new UserImportDto(
            email: $record['email'] ?? '',
            firstName: $record['first_name'] ?? '',
            lastName: $record['last_name'] ?? '',
            phone: $record['phone'] ?? '',
        );
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 10) {
            return sprintf('+1-%s-%s', substr($digits, 0, 3), substr($digits, 3, 3));
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return sprintf('+1-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3));
        }

        return $phone;
    }

    private function extractEmailDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? 'unknown';
    }

    private function inferGeolocation(TransformedUserRecord $record): array
    {
        return [
            'country' => 'US',
            'timezone' => 'America/New_York',
        ];
    }

    private function classifyUserSegment(TransformedUserRecord $record): string
    {
        $domain = $this->extractEmailDomain($record->email);

        if (in_array($domain, ['corporate.com', 'enterprise.biz'])) {
            return 'enterprise';
        }

        if (str_contains($domain, 'edu')) {
            return 'academic';
        }

        return 'consumer';
    }

    private function gatherErrors(): array
    {
        return [];
    }

    private function recordError(int $index, string $message): void
    {
        $this->logger->error('Import error', ['index' => $index, 'message' => $message]);
    }
}
