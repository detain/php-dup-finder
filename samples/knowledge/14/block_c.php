<?php
declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class ProductCodeConfig
{
    public const DEFAULT_MIN_LENGTH = 3;
    public const DEFAULT_MAX_LENGTH = 30;
    public const DEFAULT_PATTERN = '^[A-Z]{2,4}[0-9]{4,8}[A-Z0-9]{0,2}$';

    public const PREFIX_VALID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    public const NUMBER_VALID_CHARS = '0123456789';
    public const SUFFIX_VALID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public const PREFIX_MIN_LENGTH = 2;
    public const PREFIX_MAX_LENGTH = 4;
    public const NUMBER_MIN_LENGTH = 4;
    public const NUMBER_MAX_LENGTH = 8;
    public const SUFFIX_MAX_LENGTH = 2;

    private array $config;

    public function __construct(string $configPath = '')
    {
        if ($configPath !== '' && file_exists($configPath)) {
            $this->config = Yaml::parseFile($configPath);
        } else {
            $this->config = [];
        }
    }

    public function getValidationRules(): array
    {
        $productCode = $this->config['product_code'] ?? [];

        return [
            'min_length' => $productCode['min_length'] ?? self::DEFAULT_MIN_LENGTH,
            'max_length' => $productCode['max_length'] ?? self::DEFAULT_MAX_LENGTH,
            'pattern' => $productCode['pattern'] ?? self::DEFAULT_PATTERN,
            'prefix' => [
                'min_length' => $productCode['prefix_min_length'] ?? self::PREFIX_MIN_LENGTH,
                'max_length' => $productCode['prefix_max_length'] ?? self::PREFIX_MAX_LENGTH,
                'valid_chars' => $productCode['prefix_valid_chars'] ?? self::PREFIX_VALID_CHARS
            ],
            'number' => [
                'min_length' => $productCode['number_min_length'] ?? self::NUMBER_MIN_LENGTH,
                'max_length' => $productCode['number_max_length'] ?? self::NUMBER_MAX_LENGTH,
                'valid_chars' => $productCode['number_valid_chars'] ?? self::NUMBER_VALID_CHARS
            ],
            'suffix' => [
                'max_length' => $productCode['suffix_max_length'] ?? self::SUFFIX_MAX_LENGTH,
                'valid_chars' => $productCode['suffix_valid_chars'] ?? self::SUFFIX_VALID_CHARS,
                'required' => $productCode['suffix_required'] ?? false
            ]
        ];
    }

    public function validateProductCode(string $code): ValidationResult
    {
        $rules = $this->getValidationRules();
        $errors = [];

        if (strlen($code) < $rules['min_length']) {
            $errors[] = "Product code must be at least {$rules['min_length']} characters";
        }

        if (strlen($code) > $rules['max_length']) {
            $errors[] = "Product code cannot exceed {$rules['max_length']} characters";
        }

        if (!preg_match("/^{$rules['pattern']}$/", $code)) {
            $errors[] = 'Product code format is invalid';
        }

        if (!$this->hasValidStructure($code, $rules)) {
            $errors[] = 'Product code structure is invalid';
        }

        return new ValidationResult(
            valid: empty($errors),
            errors: $errors
        );
    }

    public function generateProductCode(string $prefix = '', int $numberLength = 6): string
    {
        $rules = $this->getValidationRules();

        if (empty($prefix)) {
            $prefixLength = random_int($rules['prefix']['min_length'], $rules['prefix']['max_length']);
            $prefix = $this->generateRandomString($prefixLength, $rules['prefix']['valid_chars']);
        }

        $number = $this->generateRandomString($numberLength, $rules['number']['valid_chars']);

        return $prefix . $number;
    }

    private function hasValidStructure(string $code, array $rules): bool
    {
        $prefixValidChars = $rules['prefix']['valid_chars'];
        $numberValidChars = $rules['number']['valid_chars'];
        $suffixValidChars = $rules['suffix']['valid_chars'];

        $prefixLength = 0;
        for ($i = 0; $i < strlen($code) && strpos($prefixValidChars, $code[$i]) !== false; $i++) {
            $prefixLength++;
        }

        if ($prefixLength < $rules['prefix']['min_length'] || $prefixLength > $rules['prefix']['max_length']) {
            return false;
        }

        $numberLength = 0;
        for ($i = $prefixLength; $i < strlen($code) && strpos($numberValidChars, $code[$i]) !== false; $i++) {
            $numberLength++;
        }

        if ($numberLength < $rules['number']['min_length'] || $numberLength > $rules['number']['max_length']) {
            return false;
        }

        for ($i = $prefixLength + $numberLength; $i < strlen($code); $i++) {
            if (strpos($suffixValidChars, $code[$i]) === false) {
                return false;
            }
        }

        return true;
    }

    private function generateRandomString(int $length, string $chars): string
    {
        $result = '';
        $maxIndex = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $maxIndex)];
        }

        return $result;
    }
}
