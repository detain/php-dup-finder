<?php

declare(strict_types=1);

namespace App\Application\Validation;

use App\Infrastructure\Validation\Validator;

/**
 * JSON and XML data validation service.
 * The Validator is manually injected here, duplicated from
 * FormValidatorService, ApiValidatorService, and other services.
 */
class DataValidatorService
{
    private Validator $validator;

    public function __construct(Validator $validator)
    {
        $this->validator = $validator;
    }

    public function validateJsonStructure(string $json): ValidationResult
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new ValidationResult(
                valid: false,
                errors: ['json' => 'Invalid JSON: ' . json_last_error_msg()],
            );
        }

        return new ValidationResult(valid: true, data: $data);
    }

    public function validateWebhookPayload(array $payload): ValidationResult
    {
        $this->validator->validate($payload, [
            'event' => 'required|string|in:order.placed,order.updated,order.cancelled,payment.processed,payment.failed',
            'timestamp' => 'required|timestamp',
            'data' => 'required|array',
            'data.order_id' => 'required_without:data.transaction_id|uuid',
            'data.transaction_id' => 'required_without:data.order_id|uuid',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    public function validateXmlImport(string $xml): ValidationResult
    {
        libxml_use_internal_errors(true);

        $doc = simplexml_load_string($xml);

        if ($doc === false) {
            $errors = [];
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message);
            }
            libxml_clear_errors();

            return new ValidationResult(
                valid: false,
                errors: ['xml' => implode('; ', $errors)],
            );
        }

        $this->validator->validate(['xml' => $xml], [
            'xml' => 'valid_xml',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    public function validateBatchImport(array $records): ValidationResult
    {
        if (empty($records)) {
            return new ValidationResult(
                valid: false,
                errors: ['records' => 'Batch cannot be empty'],
            );
        }

        if (count($records) > 1000) {
            return new ValidationResult(
                valid: false,
                errors: ['records' => 'Batch size cannot exceed 1000 records'],
            );
        }

        $errors = [];

        foreach ($records as $index => $record) {
            $recordErrors = $this->validateSingleRecord($record, $index);
            if (!empty($recordErrors)) {
                $errors[$index] = $recordErrors;
            }
        }

        if (!empty($errors)) {
            return new ValidationResult(
                valid: false,
                errors: $errors,
            );
        }

        return new ValidationResult(valid: true);
    }

    public function validateImportMapping(array $mapping): ValidationResult
    {
        $this->validator->validate($mapping, [
            'source_fields' => 'required|array|min:1',
            'source_fields.*' => 'required|string|max:100',
            'target_fields' => 'required|array|min:1',
            'target_fields.*' => 'required|string|max:100',
            'transformations' => 'sometimes|array',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        if (count($mapping['source_fields']) !== count($mapping['target_fields'])) {
            return new ValidationResult(
                valid: false,
                errors: ['mapping' => 'Source and target field counts must match'],
            );
        }

        return new ValidationResult(valid: true);
    }

    private function validateSingleRecord(array $record, int $index): array
    {
        $errors = [];

        if (!isset($record['sku'])) {
            $errors['sku'] = "Row {$index}: SKU is required";
        }

        if (!isset($record['name'])) {
            $errors['name'] = "Row {$index}: Name is required";
        }

        if (!isset($record['price'])) {
            $errors['price'] = "Row {$index}: Price is required";
        } elseif (!is_numeric($record['price']) || $record['price'] < 0) {
            $errors['price'] = "Row {$index}: Price must be a positive number";
        }

        return $errors;
    }
}
