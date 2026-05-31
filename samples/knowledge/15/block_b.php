<?php
declare(strict_types=1);

namespace App\Address\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'postal_codes')]
#[ORM\Index(columns: ['country_code'], name: 'idx_postal_code_country')]
class PostalCode
{
    public const US = 'US';
    public const CA = 'CA';
    public const GB = 'GB';
    public const UK = 'UK';
    public const DE = 'DE';
    public const FR = 'FR';
    public const AU = 'AU';

    public const VALID_COUNTRIES = [
        self::US, self::CA, self::GB, self::UK, self::DE, self::FR, self::AU
    ];

    public const PATTERNS = [
        self::US => '/^\d{5}(-\d{4})?$/',
        self::CA => '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i',
        self::GB => '/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i',
        self::UK => '/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i',
        self::DE => '/^\d{5}$/',
        self::FR => '/^\d{5}$/',
        self::AU => '/^\d{4}$/',
    ];

    public const FORMATS = [
        self::US => '#####(-####)',
        self::CA => 'A#A #A#A',
        self::GB => 'AA## #AA|AA# #AA|A## #AA',
        self::UK => 'AA## #AA|AA# #AA|A## #AA',
        self::DE => '#####',
        self::FR => '#####',
        self::AU => '####',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $code;

    #[ORM\Column(type: 'string', length: 2)]
    private string $countryCode;

    #[ORM\Column(type: 'string', length: 255)]
    private string $normalizedValue;

    public function __construct(string $code, string $countryCode)
    {
        $this->code = $code;
        $this->countryCode = strtoupper($countryCode);
        $this->normalizedValue = $this->normalize($code, $countryCode);
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function getNormalizedValue(): string
    {
        return $this->normalizedValue;
    }

    public function isValid(): bool
    {
        $pattern = self::PATTERNS[$this->countryCode] ?? null;

        if ($pattern === null) {
            return true;
        }

        return (bool) preg_match($pattern, $this->code);
    }

    public function getFormat(): string
    {
        return self::FORMATS[$this->countryCode] ?? 'N/A';
    }

    public static function normalize(string $code, string $countryCode): string
    {
        $code = trim(strtoupper($code));

        return match (strtoupper($countryCode)) {
            self::US => preg_replace('/[^0-9]/', '', $code),
            self::CA => preg_replace('/[^A-Z0-9]/i', '', $code),
            self::GB, self::UK => preg_replace('/[^A-Z0-9]/i', '', $code),
            default => preg_replace('/[^A-Z0-9]/i', '', $code),
        };
    }

    public static function isValidCountryCode(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), self::VALID_COUNTRIES, true);
    }
}
