<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Exception;

use App\Domain\Webhook\Entity\WebhookDelivery;
use App\Domain\Webhook\ValueObject\WebhookEndpoint;

/**
 * Webhook delivery exceptions and error codes.
 *
 * ERROR CODES AND DESCRIPTIONS (documented in docs/webhooks/errors.md):
 *
 * WEBHOOK_DELIVERY_FAILED (code: WH_001)
 * Description: Webhook delivery failed after all retry attempts
 * HTTP Status: 200 (delivery attempted), 4xx/5xx (response from endpoint)
 * Retry Behavior: Yes, with exponential backoff (3 attempts total)
 * Log Level: ERROR
 * User Message (in delivery logs): "Webhook delivery failed after all retries"
 *
 * WEBHOOK_ENDPOINT_NOT_FOUND (code: WH_002)
 * Description: Webhook endpoint URL is unreachable or returns 404
 * HTTP Status: 200 (attempted), 404 (response)
 * Retry Behavior: No - endpoint may be misconfigured
 * Log Level: WARNING
 * User Message: "Webhook endpoint not found. Please verify the URL."
 *
 * WEBHOOK_ENDPOINT_TIMEOUT (code: WH_003)
 * Description: Webhook endpoint did not respond within timeout (30 seconds)
 * HTTP Status: 200 (attempted, no response)
 * Retry Behavior: Yes, 3 attempts total
 * Log Level: WARNING
 * User Message: "Webhook endpoint timed out. Will retry."
 *
 * WEBHOOK_INVALID_PAYLOAD (code: WH_004)
 * Description: Generated webhook payload failed validation
 * HTTP Status: N/A (not sent)
 * Retry Behavior: No - bug in webhook generation
 * Log Level: ERROR
 * User Message: "Internal error generating webhook. Please contact support."
 *
 * WEBHOOK_SIGNATURE_INVALID (code: WH_005)
 * Description: HMAC signature for webhook payload is invalid
 * HTTP Status: N/A
 * Retry Behavior: No - indicates payload tampering or key mismatch
 * Log Level: CRITICAL (security event)
 * User Message: N/A
 *
 * WEBHOOK_RATE_LIMITED (code: WH_006)
 * Description: Webhook endpoint returned 429 Too Many Requests
 * HTTP Status: 429
 * Retry Behavior: Yes, after Retry-After header or 5 minutes
 * Log Level: WARNING
 * User Message: "Endpoint rate limited. Will retry later."
 *
 * WEBHOOK_SSL_ERROR (code: WH_007)
 * Description: SSL certificate validation failed
 * HTTP Status: N/A
 * Retry Behavior: No - endpoint misconfiguration
 * Log Level: ERROR
 * User Message: "SSL error for webhook endpoint. Please check certificate."
 *
 * WEBHOOK_PAYLOAD_TOO_LARGE (code: WH_008)
 * Description: Webhook payload exceeds maximum size (1MB)
 * HTTP Status: N/A
 * Retry Behavior: No - payload must be split
 * Log Level: WARNING
 * User Message: "Webhook payload too large. Consider using batch endpoints."
 *
 * WEBHOOK_SUSPENDED (code: WH_009)
 * Description: Webhook delivery suspended due to repeated failures
 * HTTP Status: N/A
 * Retry Behavior: No - manual intervention required
 * Log Level: WARNING
 * User Message: "Webhook suspended after repeated failures. Please review."
 *
 * WEBHOOK_DUPLICATE_DELIVERY (code: WH_010)
 * Description: Same webhook ID was already delivered successfully
 * HTTP Status: N/A
 * Retry Behavior: No - idempotency check
 * Log Level: INFO
 * User Message: N/A (handled internally)
 *
 * See also: docs/webhooks/errors.md and JIRA WH-2024-001
 */
class WebhookException extends \Exception
{
    public const WEBHOOK_DELIVERY_FAILED = 'WH_001';
    public const WEBHOOK_ENDPOINT_NOT_FOUND = 'WH_002';
    public const WEBHOOK_ENDPOINT_TIMEOUT = 'WH_003';
    public const WEBHOOK_INVALID_PAYLOAD = 'WH_004';
    public const WEBHOOK_SIGNATURE_INVALID = 'WH_005';
    public const WEBHOOK_RATE_LIMITED = 'WH_006';
    public const WEBHOOK_SSL_ERROR = 'WH_007';
    public const WEBHOOK_PAYLOAD_TOO_LARGE = 'WH_008';
    public const WEBHOOK_SUSPENDED = 'WH_009';
    public const WEBHOOK_DUPLICATE_DELIVERY = 'WH_010';

    private const ERROR_MESSAGES = [
        self::WEBHOOK_DELIVERY_FAILED => 'Webhook delivery failed after all retry attempts',
        self::WEBHOOK_ENDPOINT_NOT_FOUND => 'Webhook endpoint URL is unreachable or returns 404',
        self::WEBHOOK_ENDPOINT_TIMEOUT => 'Webhook endpoint did not respond within timeout',
        self::WEBHOOK_INVALID_PAYLOAD => 'Generated webhook payload failed validation',
        self::WEBHOOK_SIGNATURE_INVALID => 'HMAC signature for webhook payload is invalid',
        self::WEBHOOK_RATE_LIMITED => 'Webhook endpoint returned 429 Too Many Requests',
        self::WEBHOOK_SSL_ERROR => 'SSL certificate validation failed for webhook endpoint',
        self::WEBHOOK_PAYLOAD_TOO_LARGE => 'Webhook payload exceeds maximum size of 1MB',
        self::WEBHOOK_SUSPENDED => 'Webhook delivery suspended due to repeated failures',
        self::WEBHOOK_DUPLICATE_DELIVERY => 'Webhook ID already delivered successfully',
    ];

    private const LOG_LEVELS = [
        self::WEBHOOK_DELIVERY_FAILED => 'ERROR',
        self::WEBHOOK_ENDPOINT_NOT_FOUND => 'WARNING',
        self::WEBHOOK_ENDPOINT_TIMEOUT => 'WARNING',
        self::WEBHOOK_INVALID_PAYLOAD => 'ERROR',
        self::WEBHOOK_SIGNATURE_INVALID => 'CRITICAL',
        self::WEBHOOK_RATE_LIMITED => 'WARNING',
        self::WEBHOOK_SSL_ERROR => 'ERROR',
        self::WEBHOOK_PAYLOAD_TOO_LARGE => 'WARNING',
        self::WEBHOOK_SUSPENDED => 'WARNING',
        self::WEBHOOK_DUPLICATE_DELIVERY => 'INFO',
    ];

    private const RETRYABLE = [
        self::WEBHOOK_DELIVERY_FAILED,
        self::WEBHOOK_ENDPOINT_TIMEOUT,
        self::WEBHOOK_RATE_LIMITED,
    ];

    private string $errorCode;
    private ?WebhookDelivery $delivery;
    private ?WebhookEndpoint $endpoint;

    public function __construct(
        string $errorCode,
        ?WebhookDelivery $delivery = null,
        ?WebhookEndpoint $endpoint = null,
        ?\Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->delivery = $delivery;
        $this->endpoint = $endpoint;

        $message = self::ERROR_MESSAGES[$errorCode] ?? 'Webhook error occurred';

        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    public function getLogLevel(): string
    {
        return self::LOG_LEVELS[$this->errorCode] ?? 'ERROR';
    }

    public function getDelivery(): ?WebhookDelivery
    {
        return $this->delivery;
    }

    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'retryable' => $this->isRetryable(),
            'delivery_id' => $this->delivery?->getId()?->toString(),
            'endpoint_id' => $this->endpoint?->getId()?->toString(),
        ];
    }
}
