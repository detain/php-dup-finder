<?php

declare(strict_types=1);

namespace App\Domain\Validation\Exception;

use App\Application\Validator\ValidationError;

/**
 * Validation exceptions for request/input validation.
 *
 * ERROR CODES (per API documentation at docs/api/validation-errors.md):
 *
 * VALIDATION_ERROR (code: VAL_001)
 * Description: One or more fields failed validation
 * User Message: "Validation failed. Please check your input and try again."
 * Log Level: INFO
 *
 * VALIDATION_REQUIRED_FIELD_MISSING (code: VAL_002)
 * Description: A required field was not provided
 * User Message: "The field '{field_name}' is required."
 * Log Level: INFO
 *
 * VALIDATION_INVALID_FORMAT (code: VAL_003)
 * Description: Field value doesn't match expected format
 * User Message: "The value for '{field_name}' is invalid. Expected format: {expected_format}"
 * Log Level: INFO
 *
 * VALIDATION_STRING_TOO_SHORT (code: VAL_004)
 * Description: String is below minimum length
 * User Message: "The value for '{field_name}' must be at least {min} characters."
 * Log Level: INFO
 *
 * VALIDATION_STRING_TOO_LONG (code: VAL_005)
 * Description: String exceeds maximum length
 * User Message: "The value for '{field_name}' must be no more than {max} characters."
 * Log Level: INFO
 *
 * VALIDATION_NUMBER_OUT_OF_RANGE (code: VAL_006)
 * Description: Number is outside allowed range
 * User Message: "The value for '{field_name}' must be between {min} and {max}."
 * Log Level: INFO
 *
 * VALIDATION_INVALID_EMAIL (code: VAL_007)
 * Description: Email address format is invalid
 * User Message: "Please enter a valid email address."
 * Log Level: INFO
 *
 * VALIDATION_INVALID_URL (code: VAL_008)
 * Description: URL format is invalid
 * User Message: "Please enter a valid URL."
 * Log Level: INFO
 *
 * VALIDATION_INVALID_UUID (code: VAL_009)
 * Description: UUID format is invalid
 * User Message: "The value for '{field_name}' must be a valid UUID."
 * Log Level: INFO
 *
 * VALIDATION_INVALID_DATE (code: VAL_010)
 * Description: Date format is invalid or date doesn't exist
 * User Message: "Please enter a valid date."
 * Log Level: INFO
 *
 * VALIDATION_DATE_TOO_OLD (code: VAL_011)
 * Description: Date is before the allowed minimum
 * User Message: "The date cannot be before {min_date}."
 * Log Level: INFO
 *
 * VALIDATION_DATE_TOO_FUTURE (code: VAL_012)
 * Description: Date is after the allowed maximum
 * User Message: "The date cannot be after {max_date}."
 * Log Level: INFO
 *
 * VALIDATION_ENUM_INVALID (code: VAL_013)
 * Description: Value is not one of the allowed options
 * User Message: "The value for '{field_name}' must be one of: {allowed_values}"
 * Log Level: INFO
 *
 * VALIDATION_ARRAY_TOO_SMALL (code: VAL_014)
 * Description: Array has fewer than minimum required items
 * User Message: "At least {min} item(s) required for '{field_name}'."
 * Log Level: INFO
 *
 * VALIDATION_ARRAY_TOO_LARGE (code: VAL_015)
 * Description: Array exceeds maximum allowed items
 * User Message: "No more than {max} item(s) allowed for '{field_name}'."
 * Log Level: INFO
 *
 * VALIDATION_DUPLICATE_VALUE (code: VAL_016)
 * Description: Duplicate value found where unique value required
 * User Message: "A record with this value already exists for '{field_name}'."
 * Log Level: INFO
 *
 * See also: docs/api/VALIDATION_ERRORS.md, Confluence DOC-API-001
 */
class ValidationException extends \Exception
{
    public const VALIDATION_ERROR = 'VAL_001';
    public const VALIDATION_REQUIRED_FIELD_MISSING = 'VAL_002';
    public const VALIDATION_INVALID_FORMAT = 'VAL_003';
    public const VALIDATION_STRING_TOO_SHORT = 'VAL_004';
    public const VALIDATION_STRING_TOO_LONG = 'VAL_005';
    public const VALIDATION_NUMBER_OUT_OF_RANGE = 'VAL_006';
    public const VALIDATION_INVALID_EMAIL = 'VAL_007';
    public const VALIDATION_INVALID_URL = 'VAL_008';
    public const VALIDATION_INVALID_UUID = 'VAL_009';
    public const VALIDATION_INVALID_DATE = 'VAL_010';
    public const VALIDATION_DATE_TOO_OLD = 'VAL_011';
    public const VALIDATION_DATE_TOO_FUTURE = 'VAL_012';
    public const VALIDATION_ENUM_INVALID = 'VAL_013';
    public const VALIDATION_ARRAY_TOO_SMALL = 'VAL_014';
    public const VALIDATION_ARRAY_TOO_LARGE = 'VAL_015';
    public const VALIDATION_DUPLICATE_VALUE = 'VAL_016';

    private const ERROR_MESSAGES = [
        self::VALIDATION_ERROR => 'One or more fields failed validation',
        self::VALIDATION_REQUIRED_FIELD_MISSING => 'Required field is missing',
        self::VALIDATION_INVALID_FORMAT => 'Invalid format',
        self::VALIDATION_STRING_TOO_SHORT => 'String is too short',
        self::VALIDATION_STRING_TOO_LONG => 'String is too long',
        self::VALIDATION_NUMBER_OUT_OF_RANGE => 'Number is out of range',
        self::VALIDATION_INVALID_EMAIL => 'Invalid email address',
        self::VALIDATION_INVALID_URL => 'Invalid URL',
        self::VALIDATION_INVALID_UUID => 'Invalid UUID',
        self::VALIDATION_INVALID_DATE => 'Invalid date',
        self::VALIDATION_DATE_TOO_OLD => 'Date is too old',
        self::VALIDATION_DATE_TOO_FUTURE => 'Date is too far in the future',
        self::VALIDATION_ENUM_INVALID => 'Invalid enum value',
        self::VALIDATION_ARRAY_TOO_SMALL => 'Array has too few items',
        self::VALIDATION_ARRAY_TOO_LARGE => 'Array has too many items',
        self::VALIDATION_DUPLICATE_VALUE => 'Duplicate value found',
    ];

    private const USER_MESSAGES = [
        self::VALIDATION_ERROR => 'Validation failed. Please check your input and try again.',
        self::VALIDATION_REQUIRED_FIELD_MISSING => 'The field \'{field_name}\' is required.',
        self::VALIDATION_INVALID_FORMAT => 'The value for \'{field_name}\' is invalid. Expected format: {expected_format}',
        self::VALIDATION_STRING_TOO_SHORT => 'The value for \'{field_name}\' must be at least {min} characters.',
        self::VALIDATION_STRING_TOO_LONG => 'The value for \'{field_name}\' must be no more than {max} characters.',
        self::VALIDATION_NUMBER_OUT_OF_RANGE => 'The value for \'{field_name}\' must be between {min} and {max}.',
        self::VALIDATION_INVALID_EMAIL => 'Please enter a valid email address.',
        self::VALIDATION_INVALID_URL => 'Please enter a valid URL.',
        self::VALIDATION_INVALID_UUID => 'The value for \'{field_name}\' must be a valid UUID.',
        self::VALIDATION_INVALID_DATE => 'Please enter a valid date.',
        self::VALIDATION_DATE_TOO_OLD => 'The date cannot be before {min_date}.',
        self::VALIDATION_DATE_TOO_FUTURE => 'The date cannot be after {max_date}.',
        self::VALIDATION_ENUM_INVALID => 'The value for \'{field_name}\' must be one of: {allowed_values}',
        self::VALIDATION_ARRAY_TOO_SMALL => 'At least {min} item(s) required for \'{field_name}\'.',
        self::VALIDATION_ARRAY_TOO_LARGE => 'No more than {max} item(s) allowed for \'{field_name}\'.',
        self::VALIDATION_DUPLICATE_VALUE => 'A record with this value already exists for \'{field_name}\'.',
    ];

    private string $errorCode;
    private array $errors;

    public function __construct(string $errorCode, array $errors = [], ?\Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        $this->errors = $errors;

        $message = self::ERROR_MESSAGES[$errorCode] ?? 'Validation failed';

        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getUserMessage(?string $fieldName = null, array $placeholders = []): string
    {
        $template = self::USER_MESSAGES[$this->errorCode] ?? 'Validation failed for this field.';

        $placeholders['field_name'] = $fieldName ?? 'field';

        $message = str_replace(
            array_map(fn($k) => '{' . $k . '}', array_keys($placeholders)),
            array_values($placeholders),
            $template
        );

        return $message;
    }

    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'errors' => array_map(fn($e) => $e->toArray(), $this->errors),
        ];
    }
}
