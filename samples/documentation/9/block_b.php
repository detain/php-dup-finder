<?php

declare(strict_types=1);

namespace App\Domain\Search\Exception;

use App\Application\DTOs\Search\SearchQuery;

/**
 * Search service exceptions and error codes.
 *
 * ERROR CODES AND DESCRIPTIONS (documented in docs/search/errors.md):
 *
 * SEARCH_QUERY_TOO_SHORT (code: SEARCH_001)
 * Description: Search query is below minimum length (2 characters)
 * HTTP Status: 400 Bad Request
 * User Message: "Please enter at least 2 characters to search"
 * Log Level: INFO
 *
 * SEARCH_QUERY_TOO_LONG (code: SEARCH_002)
 * Description: Search query exceeds maximum length (500 characters)
 * HTTP Status: 400 Bad Request
 * User Message: "Search query is too long. Maximum 500 characters allowed."
 * Log Level: INFO
 *
 * SEARCH_INDEX_UNAVAILABLE (code: SEARCH_003)
 * Description: Search index is not available for queries
 * HTTP Status: 503 Service Unavailable
 * User Message: "Search is temporarily unavailable. Please try again later."
 * Log Level: CRITICAL
 * Retry Behavior: Yes, with exponential backoff
 *
 * SEARCH_TIMEOUT (code: SEARCH_004)
 * Description: Search query exceeded timeout (5 seconds)
 * HTTP Status: 200 (partial results may be returned)
 * User Message: "Search timed out. Try simplifying your query."
 * Log Level: WARNING
 * Retry Behavior: Yes
 *
 * SEARCH_INVALID_FILTER (code: SEARCH_005)
 * Description: Search filter parameter is invalid or malformed
 * HTTP Status: 400 Bad Request
 * User Message: "One or more search filters are invalid."
 * Log Level: INFO
 *
 * SEARCH_FACET_NOT_FOUND (code: SEARCH_006)
 * Description: Requested facet/aggregation does not exist
 * HTTP Status: 400 Bad Request
 * User Message: "Unknown filter option selected."
 * Log Level: INFO
 *
 * SEARCH_INDEX_REBUILD_IN_PROGRESS (code: SEARCH_007)
 * Description: Search index is being rebuilt, results may be incomplete
 * HTTP Status: 200 (with warning header)
 * User Message: "Search results may be incomplete. Index rebuild in progress."
 * Log Level: WARNING
 *
 * SEARCH_SYNTAX_ERROR (code: SEARCH_008)
 * Description: Search query has syntax error (advanced query syntax)
 * HTTP Status: 400 Bad Request
 * User Message: "Search query has a syntax error. Please check your query."
 * Log Level: INFO
 *
 * SEARCH_FEATURE_DISABLED (code: SEARCH_009)
 * Description: Requested search feature is not enabled
 * HTTP Status: 400 Bad Request
 * User Message: "This search feature is not currently available."
 * Log Level: INFO
 *
 * SEARCH_RATE_LIMITED (code: SEARCH_010)
 * Description: Too many search requests from this client
 * HTTP Status: 429 Too Many Requests
 * User Message: "Too many searches. Please wait a moment and try again."
 * Log Level: WARNING
 *
 * See also: docs/search/errors.md and JIRA SEARCH-2024-001
 */
class SearchException extends \Exception
{
    public const SEARCH_QUERY_TOO_SHORT = 'SEARCH_001';
    public const SEARCH_QUERY_TOO_LONG = 'SEARCH_002';
    public const SEARCH_INDEX_UNAVAILABLE = 'SEARCH_003';
    public const SEARCH_TIMEOUT = 'SEARCH_004';
    public const SEARCH_INVALID_FILTER = 'SEARCH_005';
    public const SEARCH_FACET_NOT_FOUND = 'SEARCH_006';
    public const SEARCH_INDEX_REBUILD_IN_PROGRESS = 'SEARCH_007';
    public const SEARCH_SYNTAX_ERROR = 'SEARCH_008';
    public const SEARCH_FEATURE_DISABLED = 'SEARCH_009';
    public const SEARCH_RATE_LIMITED = 'SEARCH_010';

    private const ERROR_MESSAGES = [
        self::SEARCH_QUERY_TOO_SHORT => 'Search query is below minimum length',
        self::SEARCH_QUERY_TOO_LONG => 'Search query exceeds maximum length',
        self::SEARCH_INDEX_UNAVAILABLE => 'Search index is not available',
        self::SEARCH_TIMEOUT => 'Search query exceeded timeout',
        self::SEARCH_INVALID_FILTER => 'Search filter parameter is invalid',
        self::SEARCH_FACET_NOT_FOUND => 'Requested facet does not exist',
        self::SEARCH_INDEX_REBUILD_IN_PROGRESS => 'Search index rebuild in progress',
        self::SEARCH_SYNTAX_ERROR => 'Search query has syntax error',
        self::SEARCH_FEATURE_DISABLED => 'Search feature is not enabled',
        self::SEARCH_RATE_LIMITED => 'Search rate limit exceeded',
    ];

    private const USER_MESSAGES = [
        self::SEARCH_QUERY_TOO_SHORT => 'Please enter at least 2 characters to search',
        self::SEARCH_QUERY_TOO_LONG => 'Search query is too long. Maximum 500 characters allowed.',
        self::SEARCH_INDEX_UNAVAILABLE => 'Search is temporarily unavailable. Please try again later.',
        self::SEARCH_TIMEOUT => 'Search timed out. Try simplifying your query.',
        self::SEARCH_INVALID_FILTER => 'One or more search filters are invalid.',
        self::SEARCH_FACET_NOT_FOUND => 'Unknown filter option selected.',
        self::SEARCH_INDEX_REBUILD_IN_PROGRESS => 'Search results may be incomplete.',
        self::SEARCH_SYNTAX_ERROR => 'Search query has a syntax error. Please check your query.',
        self::SEARCH_FEATURE_DISABLED => 'This search feature is not currently available.',
        self::SEARCH_RATE_LIMITED => 'Too many searches. Please wait a moment and try again.',
    ];

    private const HTTP_STATUS_CODES = [
        self::SEARCH_QUERY_TOO_SHORT => 400,
        self::SEARCH_QUERY_TOO_LONG => 400,
        self::SEARCH_INDEX_UNAVAILABLE => 503,
        self::SEARCH_TIMEOUT => 200,
        self::SEARCH_INVALID_FILTER => 400,
        self::SEARCH_FACET_NOT_FOUND => 400,
        self::SEARCH_INDEX_REBUILD_IN_PROGRESS => 200,
        self::SEARCH_SYNTAX_ERROR => 400,
        self::SEARCH_FEATURE_DISABLED => 400,
        self::SEARCH_RATE_LIMITED => 429,
    ];

    private const RETRYABLE = [
        self::SEARCH_INDEX_UNAVAILABLE,
        self::SEARCH_TIMEOUT,
        self::SEARCH_RATE_LIMITED,
    ];

    private string $errorCode;
    private ?SearchQuery $query;

    public function __construct(
        string $errorCode,
        ?SearchQuery $query = null,
        ?\Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->query = $query;

        $message = self::ERROR_MESSAGES[$errorCode] ?? 'Search error occurred';

        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getUserMessage(): string
    {
        return self::USER_MESSAGES[$this->errorCode]
            ?? 'An error occurred while searching. Please try again.';
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
        ];
    }
}
