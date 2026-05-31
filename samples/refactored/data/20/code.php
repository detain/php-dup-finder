<?php
declare(strict_types=1);

namespace Pipeline\Shared;

final class ProcessingErrorCodes
{
    public const INVALID_FORMAT = 'E001';
    public const MISSING_FIELD = 'E002';
    public const INVALID_VALUE = 'E003';
    public const TYPE_MISMATCH = 'E004';
    public const LENGTH_EXCEEDED = 'E005';
    public const DUPLICATE_KEY = 'E006';
    public const REFERENCE_NOT_FOUND = 'E007';
    public const CONSTRAINT_VIOLATION = 'E008';
    public const VALIDATION_FAILED = 'E009';
    public const TRANSFORMATION_ERROR = 'E010';

    public static function mapException(\Exception $e): string
    {
        return match (true) {
            $e instanceof \InvalidArgumentException => self::INVALID_VALUE,
            $e instanceof \LengthException => self::LENGTH_EXCEEDED,
            $e instanceof \UnexpectedValueException => self::TYPE_MISMATCH,
            default => self::TRANSFORMATION_ERROR,
        };
    }
}

final class ProcessingLimits
{
    public const MAX_FIELD_COUNT = 100;
    public const MAX_FIELD_LENGTH = 5000;
    public const MAX_RECORD_SIZE_BYTES = 1048576;
    public const BATCH_SIZE = 1000;
    public const PARALLEL_WORKERS = 4;
}

final class ProcessingOptions
{
    public const RETRY_ON_ERROR = true;
    public const MAX_RETRIES = 3;
    public const RETRY_DELAY_MS = 100;
    public const SKIP_INVALID_ROWS = false;
    public const TRIM_WHITESPACE = true;
    public const NULL_IF_EMPTY = true;
}

interface RecordProcessorInterface
{
    public function processRecords(array $records): ProcessingResult;
}

trait RecordProcessingLogic
{
    private ProcessingLimits $limits;
    private ProcessingOptions $options;
    private RecordValidator $validator;
    private RecordTransformer $transformer;
    private LoggerInterface $logger;

    protected function processRecordBatch(array $records): ProcessingResult
    {
        $processedCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach (array_chunk($records, $this->limits::BATCH_SIZE) as $batch) {
            $batchResult = $this->processSingleBatch($batch);
            $processedCount += $batchResult->getProcessedCount();
            $errorCount += $batchResult->getErrorCount();
            $errors = array_merge($errors, $batchResult->getErrors());
        }

        return new ProcessingResult($processedCount, $errorCount, $errors);
    }

    protected function processSingleRecord(mixed $record, int $index): RecordResult
    {
        try {
            $this->validateStructure($record);
            $this->validateValues($record);

            $transformed = $this->transformer->transform($record);
            $this->saveTransformedRecord($transformed);

            return RecordResult::success();
        } catch (\Exception $e) {
            return RecordResult::failure(
                ProcessingErrorCodes::mapException($e),
                $e->getMessage(),
                $index
            );
        }
    }

    protected function validateStructure(mixed $record): void
    {
        $fieldCount = $record->getFieldCount();
        if ($fieldCount > $this->limits::MAX_FIELD_COUNT) {
            throw new \InvalidArgumentException('Too many fields');
        }

        $recordSize = strlen(json_encode($record->toArray()));
        if ($recordSize > $this->limits::MAX_RECORD_SIZE_BYTES) {
            throw new \InvalidArgumentException('Record size exceeds limit');
        }
    }

    protected function validateValues(mixed $record): void
    {
        foreach ($record->getFields() as $fieldName => $fieldValue) {
            $processedValue = $this->preprocessValue($fieldValue);

            if (strlen((string)$processedValue) > $this->limits::MAX_FIELD_LENGTH) {
                throw new \InvalidArgumentException("Field {$fieldName} too long");
            }

            if ($processedValue !== null && !$this->validator->isValidValue($processedValue, $fieldName)) {
                throw new \InvalidArgumentException("Field {$fieldName} invalid");
            }
        }
    }

    protected function preprocessValue(mixed $value): mixed
    {
        if ($value === '' && $this->options::NULL_IF_EMPTY) {
            return null;
        }

        if ($this->options::TRIM_WHITESPACE && is_string($value)) {
            return trim($value);
        }

        return $value;
    }
}
