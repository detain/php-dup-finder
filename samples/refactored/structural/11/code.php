<?php
declare(strict_types=1);

namespace DataImport\Shared;

interface ImportStrategy
{
    public function extract(string $filePath): array;
    public function validate(array $records): array;
    public function transform(array $validatedRecords): array;
    public function enrich(array $transformedRecords): array;
    public function persist(array $enrichedRecords): array;
    public function notify(array $persistedRecords): void;
    public function getEntityName(): string;
}

abstract class BaseImportPipeline
{
    protected LoggerInterface $logger;
    protected ValidatorInterface $validator;

    private const BATCH_SIZE = 100;
    private const MAX_RETRIES = 3;

    public function process(string $filePath): ImportResult
    {
        $this->logger->info("Starting {$this->getEntityName()} import pipeline", [
            'file' => $filePath,
        ]);

        $rawRecords = $this->extract($filePath);
        $validatedRecords = $this->validate($rawRecords);
        $transformedRecords = $this->transform($validatedRecords);
        $enrichedRecords = $this->enrich($transformedRecords);
        $persistedRecords = $this->persist($enrichedRecords);
        $this->notify($persistedRecords);

        $this->logger->info("{$this->getEntityName()} import pipeline completed");

        return new ImportResult(
            totalRecords: count($rawRecords),
            successfulRecords: count($persistedRecords),
            failedRecords: count($rawRecords) - count($persistedRecords),
            errors: [],
        );
    }

    protected function extractFromCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        $headers = fgetcsv($handle);

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            $records[] = array_combine($headers, $row);
        }
        fclose($handle);

        return $records;
    }

    protected function processInBatches(array $records, callable $processor): array
    {
        $results = [];
        $batches = array_chunk($records, self::BATCH_SIZE, true);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $index => $record) {
                $results[$index] = $processor($record, $index);
            }
        }

        return $results;
    }

    abstract protected function getEntityName(): string;
}

final class UserImportPipeline extends BaseImportPipeline
{
    public function __construct(
        LoggerInterface $logger,
        ValidatorInterface $validator,
        private readonly UserRepository $userRepository,
        private readonly UserEventDispatcher $eventDispatcher,
    ) {
        $this->logger = $logger;
        $this->validator = $validator;
    }

    protected function getEntityName(): string
    {
        return 'user';
    }

    public function extract(string $filePath): array
    {
        return $this->extractFromCsv($filePath);
    }

    public function validate(array $records): array
    {
        return $records;
    }

    public function transform(array $validatedRecords): array
    {
        return $validatedRecords;
    }

    public function enrich(array $transformedRecords): array
    {
        return $transformedRecords;
    }

    public function persist(array $enrichedRecords): array
    {
        return $enrichedRecords;
    }

    public function notify(array $persistedRecords): void
    {
        foreach ($persistedRecords as $record) {
            $this->eventDispatcher->dispatch(new UserImportedEvent(
                userId: $record['email'],
                email: $record['email'],
                segment: 'imported',
                importedAt: new \DateTimeImmutable(),
            ));
        }
    }
}
