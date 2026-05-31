<?php
declare(strict_types=1);

namespace Billing\Core\Api;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateCustomerRequest
{
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'Invalid email format')]
    #[Assert\Length(max: 254, maxMessage: 'Email exceeds maximum length of 254 characters')]
    public string $email;

    #[Assert\NotBlank(message: 'First name is required')]
    #[Assert\Length(max: 100, maxMessage: 'First name cannot exceed 100 characters')]
    #[Assert\Regex(pattern: "/^[a-zA-Z\s\-\']+$/", message: 'First name contains invalid characters')]
    public string $firstName;

    #[Assert\NotBlank(message: 'Last name is required')]
    #[Assert\Length(max: 100, maxMessage: 'Last name cannot exceed 100 characters')]
    #[Assert\Regex(pattern: "/^[a-zA-Z\s\-\']+$/", message: 'Last name contains invalid characters')]
    public string $lastName;

    #[Assert\Length(min: 10, max: 15, minMessage: 'Phone must be 10-15 digits', maxMessage: 'Phone must be 10-15 digits')]
    public ?string $phone = null;

    #[Assert\NotBlank(message: 'Password is required')]
    #[Assert\Length(min: 8, max: 128, minMessage: 'Password must be at least 8 characters', maxMessage: 'Password exceeds maximum length')]
    #[Assert\Regex(pattern: '/[A-Z]/', message: 'Password must contain at least one uppercase letter')]
    #[Assert\Regex(pattern: '/[a-z]/', message: 'Password must contain at least one lowercase letter')]
    #[Assert\Regex(pattern: '/[0-9]/', message: 'Password must contain at least one number')]
    #[Assert\Regex(pattern: '/[!@#$%^&*(),.?":{}|<>]/', message: 'Password must contain at least one special character')]
    public string $password;

    public bool $marketingConsent = false;

    #[Assert\Length(min: 5, max: 20, minMessage: 'Referral code must be 5-20 characters', maxMessage: 'Referral code must be 5-20 characters')]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9]+$/', message: 'Referral code must be alphanumeric')]
    public ?string $referralCode = null;
}
