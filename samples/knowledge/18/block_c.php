<?php
declare(strict_types=1);

namespace App\User\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'email_addresses')]
class EmailAddress
{
    public const MAX_LENGTH = 254;
    public const MIN_LENGTH = 5;

    public const PATTERN = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/';

    public const BLOCKED_DOMAINS = [
        'tempmail.com', 'throwaway.com', 'fakeinbox.com',
        'mailinator.com', 'guerrillamail.com', '10minutemail.com',
        'temp-mail.org', 'getnada.com', 'mohmal.com',
        'dispostable.com', 'maildrop.cc', 'yopmail.com'
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: self::MAX_LENGTH)]
    private string $email;

    #[ORM\Column(type: 'string', length: self::MAX_LENGTH)]
    private string $normalizedEmail;

    #[ORM\Column(type: 'boolean')]
    private bool $isVerified = false;

    public function __construct(string $email)
    {
        $this->setEmail($email);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getNormalizedEmail(): string
    {
        return $this->normalizedEmail;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setEmail(string $email): void
    {
        $this->validateEmailFormat($email);

        $this->email = $email;
        $this->normalizedEmail = $this->normalize($email);
    }

    public function verify(): void
    {
        $this->isVerified = true;
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function validateEmailFormat(string $email): void
    {
        if (strlen($email) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Email exceeds maximum length of ' . self::MAX_LENGTH . ' characters'
            );
        }

        if (strlen($email) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(
                'Email must be at least ' . self::MIN_LENGTH . ' characters'
            );
        }

        if (!preg_match(self::PATTERN, $email)) {
            throw new \InvalidArgumentException('Invalid email format');
        }
    }

    public static function isDisposableDomain(string $email): bool
    {
        $domain = self::extractDomain($email);
        return in_array(strtolower($domain), self::BLOCKED_DOMAINS, true);
    }

    private static function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? '';
    }

    public static function isValidFormat(string $email): bool
    {
        if (strlen($email) < self::MIN_LENGTH || strlen($email) > self::MAX_LENGTH) {
            return false;
        }

        return (bool) preg_match(self::PATTERN, $email);
    }
}
