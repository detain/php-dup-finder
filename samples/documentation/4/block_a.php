<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exception;

use App\Domain\Payment\Entity\PaymentTransaction;

/**
 * Payment processing exceptions.
 *
 * ERROR CODES AND DESCRIPTIONS (per documentation team at DOCS-402):
 *
 * PAYMENT_DECLINED_GENERIC (code: PAY_001)
 * Description: The card was declined for unspecified reasons
 * User Message: "Your payment could not be processed. Please try again or use a different payment method."
 * Log Level: WARNING
 * Retry: YES - after 24 hours
 *
 * PAYMENT_DECLINED_INSUFFICIENT_FUNDS (code: PAY_002)
 * Description: Card has insufficient funds
 * User Message: "Your card has insufficient funds. Please try a different card or add funds to your account."
 * Log Level: WARNING
 * Retry: NO - requires customer action
 *
 * PAYMENT_DECLINED_CARD_EXPIRED (code: PAY_003)
 * Description: The card expiration date is in the past
 * User Message: "Your card has expired. Please update your payment method with a new expiration date."
 * Log Level: INFO
 * Retry: NO - requires card update
 *
 * PAYMENT_DECLINED_CARD_DECLINED_BY_ISSUER (code: PAY_004)
 * Description: Card was declined by the issuing bank
 * User Message: "Your card was declined by your bank. Please contact your bank or try a different card."
 * Log Level: WARNING
 * Retry: YES - after 1 hour
 *
 * PAYMENT_DECLINED_DO_NOT_HONOR (code: PAY_005)
 * Description: Bank refuses transaction without explanation
 * User Message: "Your transaction could not be processed. Please try a different payment method."
 * Log Level: WARNING
 * Retry: YES - after 24 hours
 *
 * PAYMENT_DECLINED_FRAUD_SUSPECTED (code: PAY_006)
 * Description: Transaction flagged as potentially fraudulent
 * User Message: "For security purposes, we couldn't process this transaction. Please contact support."
 * Log Level: CRITICAL
 * Retry: NO - requires manual review
 *
 * PAYMENT_INVALID_CARD_NUMBER (code: PAY_007)
 * Description: Card number validation failed (invalid length, checksum, or format)
 * User Message: "The card number entered is invalid. Please check and re-enter your card details."
 * Log Level: INFO
 * Retry: NO - requires card correction
 *
 * PAYMENT_INVALID_CVV (code: PAY_008)
 * Description: CVV/CVC verification failed
 * User Message: "The security code (CVV) is incorrect. Please check the back of your card."
 * Log Level: INFO
 * Retry: NO - requires card correction
 *
 * PAYMENT_INVALID_EXPIRY (code: PAY_009)
 * Description: Expiry date format invalid or in past
 * User Message: "The expiration date is invalid. Please check and re-enter your card details."
 * Log Level: INFO
 * Retry: NO - requires card correction
 *
 * PAYMENT_PROCESSING_ERROR (code: PAY_010)
 * Description: Generic processing error in the payment gateway
 * User Message: "A technical error occurred. Please try again in a few minutes."
 * Log Level: ERROR
 * Retry: YES - after exponential backoff starting at 5 minutes
 *
 * PAYMENT_GATEWAY_UNAVAILABLE (code: PAY_011)
 * Description: Payment gateway is not reachable
 * User Message: "Payment service is temporarily unavailable. Please try again later."
 * Log Level: CRITICAL
 * Retry: YES - automatic with circuit breaker (5 failures = open)
 *
 * PAYMENT_SETTLEMENT_FAILED (code: PAY_012)
 * Description: Authorized payment could not be captured/settled
 * User Message: "Your payment could not be completed. You have not been charged."
 * Log Level: ERROR
 * Retry: YES - manual re-attempt after 24 hours
 *
 * See also: docs/error-codes/PAYMENT_ERRORS.md and Confluence DOC-402
 */
class PaymentException extends \Exception
{
    public const PAYMENT_DECLINED_GENERIC = 'PAY_001';
    public const PAYMENT_DECLINED_INSUFFICIENT_FUNDS = 'PAY_002';
    public const PAYMENT_DECLINED_CARD_EXPIRED = 'PAY_003';
    public const PAYMENT_DECLINED_CARD_DECLINED_BY_ISSUER = 'PAY_004';
    public const PAYMENT_DECLINED_DO_NOT_HONOR = 'PAY_005';
    public const PAYMENT_DECLINED_FRAUD_SUSPECTED = 'PAY_006';
    public const PAYMENT_INVALID_CARD_NUMBER = 'PAY_007';
    public const PAYMENT_INVALID_CVV = 'PAY_008';
    public const PAYMENT_INVALID_EXPIRY = 'PAY_009';
    public const PAYMENT_PROCESSING_ERROR = 'PAY_010';
    public const PAYMENT_GATEWAY_UNAVAILABLE = 'PAY_011';
    public const PAYMENT_SETTLEMENT_FAILED = 'PAY_012';

    private const ERROR_MESSAGES = [
        self::PAYMENT_DECLINED_GENERIC => 'The card was declined. Please try again or use a different payment method.',
        self::PAYMENT_DECLINED_INSUFFICIENT_FUNDS => 'The card has insufficient funds.',
        self::PAYMENT_DECLINED_CARD_EXPIRED => 'The card has expired.',
        self::PAYMENT_DECLINED_CARD_DECLINED_BY_ISSUER => 'The card was declined by the issuing bank.',
        self::PAYMENT_DECLINED_DO_NOT_HONOR => 'The transaction was refused by the bank.',
        self::PAYMENT_DECLINED_FRAUD_SUSPECTED => 'The transaction was flagged for potential fraud.',
        self::PAYMENT_INVALID_CARD_NUMBER => 'The card number is invalid.',
        self::PAYMENT_INVALID_CVV => 'The card verification code is incorrect.',
        self::PAYMENT_INVALID_EXPIRY => 'The card expiration date is invalid.',
        self::PAYMENT_PROCESSING_ERROR => 'A processing error occurred.',
        self::PAYMENT_GATEWAY_UNAVAILABLE => 'The payment service is temporarily unavailable.',
        self::PAYMENT_SETTLEMENT_FAILED => 'The payment could not be completed.',
    ];

    private const LOG_LEVELS = [
        self::PAYMENT_DECLINED_GENERIC => 'WARNING',
        self::PAYMENT_DECLINED_INSUFFICIENT_FUNDS => 'WARNING',
        self::PAYMENT_DECLINED_CARD_EXPIRED => 'INFO',
        self::PAYMENT_DECLINED_CARD_DECLINED_BY_ISSUER => 'WARNING',
        self::PAYMENT_DECLINED_DO_NOT_HONOR => 'WARNING',
        self::PAYMENT_DECLINED_FRAUD_SUSPECTED => 'CRITICAL',
        self::PAYMENT_INVALID_CARD_NUMBER => 'INFO',
        self::PAYMENT_INVALID_CVV => 'INFO',
        self::PAYMENT_INVALID_EXPIRY => 'INFO',
        self::PAYMENT_PROCESSING_ERROR => 'ERROR',
        self::PAYMENT_GATEWAY_UNAVAILABLE => 'CRITICAL',
        self::PAYMENT_SETTLEMENT_FAILED => 'ERROR',
    ];

    private const USER_FACING_MESSAGES = [
        self::PAYMENT_DECLINED_GENERIC => 'Your payment could not be processed. Please try again or use a different payment method.',
        self::PAYMENT_DECLINED_INSUFFICIENT_FUNDS => 'Your card has insufficient funds. Please try a different card or add funds to your account.',
        self::PAYMENT_DECLINED_CARD_EXPIRED => 'Your card has expired. Please update your payment method with a new expiration date.',
        self::PAYMENT_DECLINED_CARD_DECLINED_BY_ISSUER => 'Your card was declined by your bank. Please contact your bank or try a different card.',
        self::PAYMENT_DECLINED_DO_NOT_HONOR => 'Your transaction could not be processed. Please try a different payment method.',
        self::PAYMENT_DECLINED_FRAUD_SUSPECTED => 'For security purposes, we couldn\'t process this transaction. Please contact support.',
        self::PAYMENT_INVALID_CARD_NUMBER => 'The card number entered is invalid. Please check and re-enter your card details.',
        self::PAYMENT_INVALID_CVV => 'The security code (CVV) is incorrect. Please check the back of your card.',
        self::PAYMENT_INVALID_EXPIRY => 'The expiration date is invalid. Please check and re-enter your card details.',
        self::PAYMENT_PROCESSING_ERROR => 'A technical error occurred. Please try again in a few minutes.',
        self::PAYMENT_GATEWAY_UNAVAILABLE => 'Payment service is temporarily unavailable. Please try again later.',
        self::PAYMENT_SETTLEMENT_FAILED => 'Your payment could not be completed. You have not been charged.',
    ];

    private const RETRYABLE = [
        self::PAYMENT_DECLINED_GENERIC,
        self::PAYMENT_DECLINED_CARD_DECLINED_BY_ISSUER,
        self::PAYMENT_DECLINED_DO_NOT_HONOR,
        self::PAYMENT_PROCESSING_ERROR,
        self::PAYMENT_GATEWAY_UNAVAILABLE,
        self::PAYMENT_SETTLEMENT_FAILED,
    ];

    private string $errorCode;
    private ?PaymentTransaction $transaction;
    private array $context;

    public function __construct(
        string $errorCode,
        ?PaymentTransaction $transaction = null,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->transaction = $transaction;
        $this->context = $context;

        $message = self::ERROR_MESSAGES[$errorCode] ?? 'An unknown payment error occurred.';

        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getTransaction(): ?PaymentTransaction
    {
        return $this->transaction;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getUserMessage(): string
    {
        return self::USER_FACING_MESSAGES[$this->errorCode]
            ?? 'An error occurred while processing your payment. Please try again.';
    }

    public function getLogLevel(): string
    {
        return self::LOG_LEVELS[$this->errorCode] ?? 'ERROR';
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
            'retryable' => $this->isRetryable(),
            'transaction_id' => $this->transaction?->getId()?->toString(),
            'context' => $this->context,
        ];
    }
}
