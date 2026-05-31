<?php

declare(strict_types=1);

namespace App\Domain\FileStorage\Exception;

use App\Domain\FileStorage\Entity\StoredFile;
use App\Domain\FileStorage\ValueObject\StorageBucket;

/**
 * File storage and CDN exceptions and error codes.
 *
 * ERROR CODES AND DESCRIPTIONS (documented in docs/storage/errors.md):
 *
 * STORAGE_UPLOAD_TOO_LARGE (code: FS_001)
 * Description: Uploaded file exceeds maximum size limit (100MB)
 * HTTP Status: 413 Payload Too Large
 * User Message: "File is too large. Maximum file size is 100MB."
 * Log Level: INFO
 *
 * STORAGE_UPLOAD_INVALID_TYPE (code: FS_002)
 * Description: File MIME type is not allowed
 * HTTP Status: 415 Unsupported Media Type
 * User Message: "This file type is not allowed. Allowed types: {list}"
 * Log Level: INFO
 *
 * STORAGE_BUCKET_NOT_FOUND (code: FS_003)
 * Description: Storage bucket does not exist or is not accessible
 * HTTP Status: 404 Not Found
 * User Message: "Storage configuration error. Please contact support."
 * Log Level: ERROR
 *
 * STORAGE_QUOTA_EXCEEDED (code: FS_004)
 * Description: User or organization storage quota exceeded
 * HTTP Status: 507 Insufficient Storage
 * User Message: "Storage quota exceeded. Please upgrade your plan or delete files."
 * Log Level: WARNING
 *
 * STORAGE_UPLOAD_IN_PROGRESS (code: FS_005)
 * Description: File upload was interrupted or incomplete
 * HTTP Status: 409 Conflict
 * User Message: "File upload was interrupted. Please try again."
 * Log Level: WARNING
 *
 * STORAGE_FILE_NOT_FOUND (code: FS_006)
 * Description: Requested file does not exist or has been deleted
 * HTTP Status: 404 Not Found
 * User Message: "File not found. It may have been deleted."
 * Log Level: INFO
 *
 * STORAGE_PERMISSION_DENIED (code: FS_007)
 * Description: User does not have permission to access the file
 * HTTP Status: 403 Forbidden
 * User Message: "You do not have permission to access this file."
 * Log Level: WARNING
 *
 * STORAGE_SIGNATURE_EXPIRED (code: FS_008)
 * Description: Pre-signed URL has expired
 * HTTP Status: 403 Forbidden
 * User Message: "Download link has expired. Please request a new one."
 * Log Level: INFO
 *
 * STORAGE_SIGNATURE_INVALID (code: FS_009)
 * Description: Pre-signed URL signature is invalid
 * HTTP Status: 403 Forbidden
 * User Message: "Invalid download link. Please request a new one."
 * Log Level: WARNING (potential tampering)
 *
 * STORAGE_CDN_UNAVAILABLE (code: FS_010)
 * Description: CDN is not available for file delivery
 * HTTP Status: 503 Service Unavailable
 * User Message: "Content delivery is temporarily unavailable. Try again later."
 * Log Level: CRITICAL
 * Retry Behavior: Yes, fallback to origin
 *
 * STORAGE_ORIGIN_UNREACHABLE (code: FS_011)
 * Description: Cannot reach origin storage server
 * HTTP Status: 503 Service Unavailable
 * User Message: "File is temporarily unavailable. Please try again later."
 * Log Level: CRITICAL
 * Retry Behavior: Yes
 *
 * STORAGE_COPY_FAILED (code: FS_012)
 * Description: Failed to copy file within or between buckets
 * HTTP Status: 500 Internal Server Error
 * User Message: "Failed to process file. Please try again."
 * Log Level: ERROR
 * Retry Behavior: Yes
 *
 * STORAGE_DELETE_IN_PROGRESS (code: FS_013)
 * Description: File is scheduled for deletion
 * HTTP Status: 410 Gone
 * User Message: "This file is scheduled for deletion and no longer available."
 * Log Level: INFO
 *
 * See also: docs/storage/errors.md and JIRA STORAGE-2024-001
 */
class StorageException extends \Exception
{
    public const STORAGE_UPLOAD_TOO_LARGE = 'FS_001';
    public const STORAGE_UPLOAD_INVALID_TYPE = 'FS_002';
    public const STORAGE_BUCKET_NOT_FOUND = 'FS_003';
    public const STORAGE_QUOTA_EXCEEDED = 'FS_004';
    public const STORAGE_UPLOAD_IN_PROGRESS = 'FS_005';
    public const STORAGE_FILE_NOT_FOUND = 'FS_006';
    public const STORAGE_PERMISSION_DENIED = 'FS_007';
    public const STORAGE_SIGNATURE_EXPIRED = 'FS_008';
    public const STORAGE_SIGNATURE_INVALID = 'FS_009';
    public const STORAGE_CDN_UNAVAILABLE = 'FS_010';
    public const STORAGE_ORIGIN_UNREACHABLE = 'FS_011';
    public const STORAGE_COPY_FAILED = 'FS_012';
    public const STORAGE_DELETE_IN_PROGRESS = 'FS_013';

    private const ERROR_MESSAGES = [
        self::STORAGE_UPLOAD_TOO_LARGE => 'Uploaded file exceeds maximum size limit',
        self::STORAGE_UPLOAD_INVALID_TYPE => 'File MIME type is not allowed',
        self::STORAGE_BUCKET_NOT_FOUND => 'Storage bucket does not exist',
        self::STORAGE_QUOTA_EXCEEDED => 'Storage quota has been exceeded',
        self::STORAGE_UPLOAD_IN_PROGRESS => 'File upload was interrupted',
        self::STORAGE_FILE_NOT_FOUND => 'Requested file does not exist',
        self::STORAGE_PERMISSION_DENIED => 'Permission denied for file access',
        self::STORAGE_SIGNATURE_EXPIRED => 'Pre-signed URL has expired',
        self::STORAGE_SIGNATURE_INVALID => 'Pre-signed URL signature is invalid',
        self::STORAGE_CDN_UNAVAILABLE => 'CDN is not available',
        self::STORAGE_ORIGIN_UNREACHABLE => 'Cannot reach origin storage server',
        self::STORAGE_COPY_FAILED => 'Failed to copy file',
        self::STORAGE_DELETE_IN_PROGRESS => 'File is scheduled for deletion',
    ];

    private const USER_MESSAGES = [
        self::STORAGE_UPLOAD_TOO_LARGE => 'File is too large. Maximum file size is 100MB.',
        self::STORAGE_UPLOAD_INVALID_TYPE => 'This file type is not allowed.',
        self::STORAGE_BUCKET_NOT_FOUND => 'Storage configuration error. Please contact support.',
        self::STORAGE_QUOTA_EXCEEDED => 'Storage quota exceeded. Please upgrade or delete files.',
        self::STORAGE_UPLOAD_IN_PROGRESS => 'File upload was interrupted. Please try again.',
        self::STORAGE_FILE_NOT_FOUND => 'File not found. It may have been deleted.',
        self::STORAGE_PERMISSION_DENIED => 'You do not have permission to access this file.',
        self::STORAGE_SIGNATURE_EXPIRED => 'Download link has expired. Please request a new one.',
        self::STORAGE_SIGNATURE_INVALID => 'Invalid download link. Please request a new one.',
        self::STORAGE_CDN_UNAVAILABLE => 'Content delivery is temporarily unavailable.',
        self::STORAGE_ORIGIN_UNREACHABLE => 'File is temporarily unavailable. Please try again later.',
        self::STORAGE_COPY_FAILED => 'Failed to process file. Please try again.',
        self::STORAGE_DELETE_IN_PROGRESS => 'This file is no longer available.',
    ];

    private const HTTP_STATUS_CODES = [
        self::STORAGE_UPLOAD_TOO_LARGE => 413,
        self::STORAGE_UPLOAD_INVALID_TYPE => 415,
        self::STORAGE_BUCKET_NOT_FOUND => 404,
        self::STORAGE_QUOTA_EXCEEDED => 507,
        self::STORAGE_UPLOAD_IN_PROGRESS => 409,
        self::STORAGE_FILE_NOT_FOUND => 404,
        self::STORAGE_PERMISSION_DENIED => 403,
        self::STORAGE_SIGNATURE_EXPIRED => 403,
        self::STORAGE_SIGNATURE_INVALID => 403,
        self::STORAGE_CDN_UNAVAILABLE => 503,
        self::STORAGE_ORIGIN_UNREACHABLE => 503,
        self::STORAGE_COPY_FAILED => 500,
        self::STORAGE_DELETE_IN_PROGRESS => 410,
    ];

    private const RETRYABLE = [
        self::STORAGE_CDN_UNAVAILABLE,
        self::STORAGE_ORIGIN_UNREACHABLE,
        self::STORAGE_COPY_FAILED,
    ];

    private string $errorCode;
    private ?StoredFile $storedFile;
    private ?StorageBucket $bucket;

    public function __construct(
        string $errorCode,
        ?StoredFile $storedFile = null,
        ?StorageBucket $bucket = null,
        ?\Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->storedFile = $storedFile;
        $this->bucket = $bucket;

        $message = self::ERROR_MESSAGES[$errorCode] ?? 'Storage error occurred';

        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getUserMessage(): string
    {
        return self::USER_MESSAGES[$this->errorCode] ?? 'A storage error occurred.';
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS_CODES[$this->errorCode] ?? 500;
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'user_message' => $this->getUserMessage(),
            'http_status' => $this->getHttpStatusCode(),
            'retryable' => $this->isRetryable(),
            'file_id' => $this->storedFile?->getId()?->toString(),
            'bucket' => $this->bucket?->getName(),
        ];
    }
}
