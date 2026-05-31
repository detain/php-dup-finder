<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exception;

use App\Domain\Auth\Entity\AttemptedLogin;

/**
 * Authentication and authorization exceptions.
 *
 * ERROR CODES AND DESCRIPTIONS (documented in docs/security/error-codes.md):
 *
 * AUTH_CREDENTIALS_INVALID (code: AUTH_001)
 * Description: Email/password combination does not match any account
 * User Message: "The email or password you entered is incorrect."
 * Log Level: INFO (to prevent username enumeration, we don't reveal which field is wrong)
 * Account Lockout: After 5 failed attempts
 *
 * AUTH_ACCOUNT_LOCKED (code: AUTH_002)
 * Description: Account locked due to too many failed login attempts
 * User Message: "Your account has been locked due to too many failed login attempts. Please try again in 30 minutes or reset your password."
 * Log Level: WARNING
 * Lockout Duration: 30 minutes (configurable)
 *
 * AUTH_ACCOUNT_DISABLED (code: AUTH_003)
 * Description: User account has been disabled by an administrator
 * User Message: "This account has been disabled. Please contact support for assistance."
 * Log Level: INFO
 *
 * AUTH_ACCOUNT_PENDING_VERIFICATION (code: AUTH_004)
 * Description: Account created but email not yet verified
 * User Message: "Please verify your email address to sign in. Check your inbox for the verification link."
 * Log Level: INFO
 *
 * AUTH_EMAIL_ALREADY_VERIFIED (code: AUTH_005)
 * Description: Verification token already used or account already verified
 * User Message: "This email has already been verified. Please sign in."
 * Log Level: INFO
 *
 * AUTH_VERIFICATION_TOKEN_EXPIRED (code: AUTH_006)
 * Description: The verification token has expired (tokens valid for 24 hours)
 * User Message: "This verification link has expired. Please request a new one."
 * Log Level: INFO
 *
 * AUTH_VERIFICATION_TOKEN_INVALID (code: AUTH_007)
 * Description: The verification token is malformed or doesn't exist
 * User Message: "This verification link is invalid. Please request a new one."
 * Log Level: WARNING (might indicate tampering)
 *
 * AUTH_PASSWORD_RESET_TOKEN_EXPIRED (code: AUTH_008)
 * Description: Password reset token has expired (tokens valid for 1 hour)
 * User Message: "This password reset link has expired. Please request a new one."
 * Log Level: INFO
 *
 * AUTH_PASSWORD_RESET_TOKEN_INVALID (code: AUTH_009)
 * Description: Password reset token is malformed or doesn't exist
 * User Message: "This password reset link is invalid. Please request a new one."
 * Log Level: WARNING
 *
 * AUTH_PASSWORD_WEAK (code: AUTH_010)
 * Description: Password does not meet security requirements
 * User Message: "Your password does not meet security requirements. It must be at least 8 characters and include uppercase, lowercase, and numeric characters."
 * Log Level: INFO
 *
 * AUTH_PASSWORD_COMPROMISED (code: AUTH_011)
 * Description: Password found in known data breaches (HIBP check)
 * User Message: "This password has been found in a data breach. Please choose a different password."
 * Log Level: WARNING
 *
 * AUTH_SESSION_EXPIRED (code: AUTH_012)
 * Description: User session has expired
 * User Message: "Your session has expired. Please sign in again."
 * Log Level: INFO
 *
 * AUTH_SESSION_INVALID (code: AUTH_013)
 * Description: Session token is invalid, malformed, or revoked
 * User Message: "Your session is invalid. Please sign in again."
 * Log Level: WARNING (might indicate token theft)
 *
 * AUTH_2FA_CODE_INVALID (code: AUTH_014)
 * Description: The two-factor authentication code is incorrect
 * User Message: "The authentication code you entered is incorrect. Please check your authenticator app and try again."
 * Log Level: WARNING
 * Rate Limit: After 3 wrong codes, 2FA is disabled and password must be re-entered
 *
 * AUTH_2FA_LOCKED (code: AUTH_015)
 * Description: Too many incorrect 2FA codes entered
 * User Message: "Too many incorrect codes. Please sign in again with your password."
 * Log Level: WARNING
 *
 * AUTH_PERMISSION_DENIED (code: AUTH_016)
 * Description: User doesn't have permission to access the requested resource
 * User Message: "You do not have permission to perform this action."
 * Log Level: WARNING
 *
 * AUTH_RATE_LIMIT_EXCEEDED (code: AUTH_017)
 * Description: Too many authentication attempts from this IP/device
 * User Message: "Too many login attempts. Please wait a few minutes before trying again."
 * Log Level: WARNING
 *
 * See also: docs/security/AUTH_ERRORS.md and JIRA ticket SEC-234
 */
class AuthenticationException extends \Exception
{
    public const AUTH_CREDENTIALS_INVALID = 'AUTH_001';
    public const AUTH_ACCOUNT_LOCKED = 'AUTH_002';
    public const AUTH_ACCOUNT_DISABLED = 'AUTH_003';
    public const AUTH_ACCOUNT_PENDING_VERIFICATION = 'AUTH_004';
    public const AUTH_EMAIL_ALREADY_VERIFIED = 'AUTH_005';
    public const AUTH_VERIFICATION_TOKEN_EXPIRED = 'AUTH_006';
    public const AUTH_VERIFICATION_TOKEN_INVALID = 'AUTH_007';
    public const AUTH_PASSWORD_RESET_TOKEN_EXPIRED = 'AUTH_008';
    public const AUTH_PASSWORD_RESET_TOKEN_INVALID = 'AUTH_009';
    public const AUTH_PASSWORD_WEAK = 'AUTH_010';
    public const AUTH_PASSWORD_COMPROMISED = 'AUTH_011';
    public const AUTH_SESSION_EXPIRED = 'AUTH_012';
    public const AUTH_SESSION_INVALID = 'AUTH_013';
    public const AUTH_2FA_CODE_INVALID = 'AUTH_014';
    public const AUTH_2FA_LOCKED = 'AUTH_015';
    public const AUTH_PERMISSION_DENIED = 'AUTH_016';
    public const AUTH_RATE_LIMIT_EXCEEDED = 'AUTH_017';

    private const ERROR_MESSAGES = [
        self::AUTH_CREDENTIALS_INVALID => 'Invalid credentials',
        self::AUTH_ACCOUNT_LOCKED => 'Account is locked',
        self::AUTH_ACCOUNT_DISABLED => 'Account is disabled',
        self::AUTH_ACCOUNT_PENDING_VERIFICATION => 'Email not verified',
        self::AUTH_EMAIL_ALREADY_VERIFIED => 'Email already verified',
        self::AUTH_VERIFICATION_TOKEN_EXPIRED => 'Verification token expired',
        self::AUTH_VERIFICATION_TOKEN_INVALID => 'Invalid verification token',
        self::AUTH_PASSWORD_RESET_TOKEN_EXPIRED => 'Password reset token expired',
        self::AUTH_PASSWORD_RESET_TOKEN_INVALID => 'Invalid password reset token',
        self::AUTH_PASSWORD_WEAK => 'Password does not meet requirements',
        self::AUTH_PASSWORD_COMPROMISED => 'Password found in data breach',
        self::AUTH_SESSION_EXPIRED => 'Session has expired',
        self::AUTH_SESSION_INVALID => 'Invalid session',
        self::AUTH_2FA_CODE_INVALID => 'Invalid 2FA code',
        self::AUTH_2FA_LOCKED => '2FA is locked due to too many attempts',
        self::AUTH_PERMISSION_DENIED => 'Permission denied',
        self::AUTH_RATE_LIMIT_EXCEEDED => 'Rate limit exceeded',
    ];

    private const USER_MESSAGES = [
        self::AUTH_CREDENTIALS_INVALID => 'The email or password you entered is incorrect.',
        self::AUTH_ACCOUNT_LOCKED => 'Your account has been locked due to too many failed login attempts. Please try again in 30 minutes or reset your password.',
        self::AUTH_ACCOUNT_DISABLED => 'This account has been disabled. Please contact support for assistance.',
        self::AUTH_ACCOUNT_PENDING_VERIFICATION => 'Please verify your email address to sign in.',
        self::AUTH_EMAIL_ALREADY_VERIFIED => 'This email has already been verified. Please sign in.',
        self::AUTH_VERIFICATION_TOKEN_EXPIRED => 'This verification link has expired. Please request a new one.',
        self::AUTH_VERIFICATION_TOKEN_INVALID => 'This verification link is invalid. Please request a new one.',
        self::AUTH_PASSWORD_RESET_TOKEN_EXPIRED => 'This password reset link has expired. Please request a new one.',
        self::AUTH_PASSWORD_RESET_TOKEN_INVALID => 'This password reset link is invalid. Please request a new one.',
        self::AUTH_PASSWORD_WEAK => 'Your password must be at least 8 characters and include uppercase, lowercase, and numeric characters.',
        self::AUTH_PASSWORD_COMPROMISED => 'This password has been found in a data breach. Please choose a different password.',
        self::AUTH_SESSION_EXPIRED => 'Your session has expired. Please sign in again.',
        self::AUTH_SESSION_INVALID => 'Your session is invalid. Please sign in again.',
        self::AUTH_2FA_CODE_INVALID => 'The authentication code you entered is incorrect.',
        self::AUTH_2FA_LOCKED => 'Too many incorrect codes. Please sign in again with your password.',
        self::AUTH_PERMISSION_DENIED => 'You do not have permission to perform this action.',
        self::AUTH_RATE_LIMIT_EXCEEDED => 'Too many login attempts. Please wait a few minutes before trying again.',
    ];

    private const LOG_LEVELS = [
        self::AUTH_CREDENTIALS_INVALID => 'INFO',
        self::AUTH_ACCOUNT_LOCKED => 'WARNING',
        self::AUTH_ACCOUNT_DISABLED => 'INFO',
        self::AUTH_ACCOUNT_PENDING_VERIFICATION => 'INFO',
        self::AUTH_EMAIL_ALREADY_VERIFIED => 'INFO',
        self::AUTH_VERIFICATION_TOKEN_EXPIRED => 'INFO',
        self::AUTH_VERIFICATION_TOKEN_INVALID => 'WARNING',
        self::AUTH_PASSWORD_RESET_TOKEN_EXPIRED => 'INFO',
        self::AUTH_PASSWORD_RESET_TOKEN_INVALID => 'WARNING',
        self::AUTH_PASSWORD_WEAK => 'INFO',
        self::AUTH_PASSWORD_COMPROMISED => 'WARNING',
        self::AUTH_SESSION_EXPIRED => 'INFO',
        self::AUTH_SESSION_INVALID => 'WARNING',
        self::AUTH_2FA_CODE_INVALID => 'WARNING',
        self::AUTH_2FA_LOCKED => 'WARNING',
        self::AUTH_PERMISSION_DENIED => 'WARNING',
        self::AUTH_RATE_LIMIT_EXCEEDED => 'WARNING',
    ];

    private string $errorCode;
    private ?string $email;
    private ?string $ipAddress;

    public function __construct(
        string $errorCode,
        ?string $email = null,
        ?string $ipAddress = null,
        ?\Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->email = $email;
        $this->ipAddress = $ipAddress;

        $message = self::ERROR_MESSAGES[$errorCode] ?? 'Authentication failed';

        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getUserMessage(): string
    {
        return self::USER_MESSAGES[$this->errorCode]
            ?? 'An authentication error occurred. Please try again.';
    }

    public function getLogLevel(): string
    {
        return self::LOG_LEVELS[$this->errorCode] ?? 'ERROR';
    }

    public function shouldRevealEmailNotFound(): bool
    {
        return $this->errorCode === self::AUTH_CREDENTIALS_INVALID;
    }

    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'user_message' => $this->getUserMessage(),
            'email' => $this->email,
            'ip_address' => $this->ipAddress,
        ];
    }
}
