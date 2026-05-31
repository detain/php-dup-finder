<?php
declare(strict_types=1);

namespace DataPipe\Pipeline\Processing;

use Psr\Log\LoggerInterface;
use DataPipe\Pipeline\Entities\XmlRecord;

final class XmlRecordProcessor
{
    private const ERROR_CODE_INVALID_FORMAT = 'E001';
    private const ERROR_CODE_MISSING_FIELD = 'E002';
    private const ERROR_CODE_INVALID_VALUE = 'E003';
    private const ERROR_CODE_TYPE_MISMATCH = 'E004';
    private const ERROR_CODE_LENGTH_EXCEEDED = 'E005';
    private const ERROR_CODE_DUPLICATE_KEY = 'E006';
    private const ERROR_CODE_REFERENCE_NOT_FOUND = 'E007';
    private const ERROR_CODE_CONSTRAINT_VIOLATION = 'E008';
    private const ERROR_CODE_VALIDATION_FAILED = 'E009';
    private const ERROR_CODE_TRANSFORMATION_ERROR = 'E010';

    private const LOG_LEVEL_DEBUG = 'debug';
    private const LOG_LEVEL_INFO = 'info';
    private const LOG_LEVEL_WARNING = 'warning';
    private const LOG_LEVEL_ERROR = 'error';
    private const LOG_LEVEL_CRITICAL = 'critical';

    private const MAX_FIELD_COUNT = 100;
    private const MAX_FIELD_LENGTH = 5000;
    private const MAX_RECORD_SIZE_BYTES = 1048576;
    private const BATCH_SIZE = 1000;
    private const PARALLEL_WORKERS = 4;

    private const RETRY_ON_ERROR = true;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 100;

    private const SKIP_INVALID_ROWS = false;
    private const TRIM_WHITESPACE = true;
    private const NULL_IF_EMPTY = true;

    public function __construct(
        private readonly RecordValidator $validator,
        private readonly RecordTransformer $transformer,
        private readonly LoggerInterface $logger,
    ) {}

    public function processRecords(array $records): ProcessingResult
    {
        $this->logger->info('Starting XML record processing', [
            'record_count' => count($records),
        ]);

        $processedCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach (array_chunk($records, self::BATCH_SIZE) as $batch) {
            $batchResult = $this->processBatch($batch);

            $processedCount += $batchResult->getProcessedCount();
            $errorCount += $batchResult->getErrorCount();
            $errors = array_merge($errors, $batchResult->getErrors());

            $this->logger->debug('XML batch processed', [
                'batch_size' => count($batch),
                'processed' => $batchResult->getProcessedCount(),
                'errors' => $batchResult->getErrorCount(),
            ]);
        }

        $this->logger->info('XML record processing completed', [
            'total_processed' => $processedCount,
            'total_errors' => $errorCount,
        ]);

        return new ProcessingResult(
            processedCount: $processedCount,
            errorCount: $errorCount,
            errors: $errors
        );
    }

    private function processBatch(array $records): BatchResult
    {
        $processedCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($records as $index => $record) {
            try {
                $this->validateRecordStructure($record);
                $this->validateRecordValues($record);

                $transformed = $this->transformer->transform($record);
                $this->saveTransformedRecord($transformed);

                $processedCount++;

                $this->logger->debug('XML Record processed successfully', [
                    'record_id' => $record->getId(),
                ]);
            } catch (\Exception $e) {
                $errorCount++;
                $errorRecord = [
                    'code' => $this->mapExceptionToErrorCode($e),
                    'message' => $e->getMessage(),
                    'record_index' => $index,
                    'record_id' => $record->getId(),
                    'timestamp' => date('c'),
                ];

                if (!self::SKIP_INVALID_ROWS) {
                    $errors[] = $errorRecord;
                }

                $this->logger->error('XML Record processing failed', [
                    'error_code' => $errorRecord['code'],
                    'error_message' => $e->getMessage(),
                    'record_id' => $record->getId(),
                ]);
            }
        }

        return new BatchResult($processedCount, $errorCount, $errors);
    }

    private function validateRecordStructure(XmlRecord $record): void
    {
        $fieldCount = $record->getFieldCount();
        if ($fieldCount > self::MAX_FIELD_COUNT) {
            throw new \InvalidArgumentException(sprintf(
                'XML Record exceeds maximum field count of %d',
                self::MAX_FIELD_COUNT
            ));
        }

        $recordSize = strlen(json_encode($record->toArray()));
        if ($recordSize > self::MAX_RECORD_SIZE_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'XML Record size %d bytes exceeds maximum of %d bytes',
                $recordSize,
                self::MAX_RECORD_SIZE_BYTES
            ));
        }
    }

    private function validateRecordValues(XmlRecord $record): void
    {
        foreach ($record->getFields() as $fieldName => $fieldValue) {
            $processedValue = $this->preprocessFieldValue($fieldValue);

            if (strlen((string)$processedValue) > self::MAX_FIELD_LENGTH) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" value length %d exceeds maximum of %d',
                    $fieldName,
                    strlen((string)$processedValue),
                    self::MAX_FIELD_LENGTH
                ));
            }

            if ($processedValue !== null && !$this->validator->isValidValue($processedValue, $fieldName)) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" has invalid XML value',
                    $fieldName
                ));
            }
        }
    }

    private function preprocessFieldValue(mixed $value): mixed
    {
        if ($value === '') {
            return self::NULL_IF_EMPTY ? null : $value;
        }

        if (self::TRIM_WHITESPACE && is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    private function mapExceptionToErrorCode(\Exception $e): string
    {
        return match (true) {
            $e instanceof \InvalidArgumentException => self::ERROR_CODE_INVALID_VALUE,
            $e instanceof \LengthException => self::ERROR_CODE_LENGTH_EXCEEDED,
            $e instanceof \UnexpectedValueException => self::ERROR_CODE_TYPE_MISMATCH,
            default => self::ERROR_CODE_TRANSFORMATION_ERROR,
        };
    }

    private function saveTransformedRecord(array $transformed): void
    {
        $this->validator->getRepository()->save($transformed);
    }
}
