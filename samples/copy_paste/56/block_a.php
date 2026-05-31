<?php

declare(strict_types=1);

namespace App\Helpers;

class ValidationHelper
{
    public static function required($value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && count($value) === 0) {
            return false;
        }

        return true;
    }

    public static function email($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function url($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    public static function numeric($value): bool
    {
        return is_numeric($value);
    }

    public static function integer($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    public static function float($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    public static function boolean($value): bool
    {
        return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true);
    }

    public static function min($value, int $min): bool
    {
        if (is_numeric($value)) {
            return $value >= $min;
        }

        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }

        if (is_array($value)) {
            return count($value) >= $min;
        }

        return false;
    }

    public static function max($value, int $max): bool
    {
        if (is_numeric($value)) {
            return $value <= $max;
        }

        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }

        if (is_array($value)) {
            return count($value) <= $max;
        }

        return false;
    }

    public static function between($value, int $min, int $max): bool
    {
        return self::min($value, $min) && self::max($value, $max);
    }

    public static function in($value, array $values): bool
    {
        return in_array($value, $values, true);
    }

    public static function notIn($value, array $values): bool
    {
        return !in_array($value, $values, true);
    }

    public static function regex($value, string $pattern): bool
    {
        return preg_match($pattern, $value) === 1;
    }

    public static function alpha($value): bool
    {
        return preg_match('/^[\pL]+$/u', $value) === 1;
    }

    public static function alphanumeric($value): bool
    {
        return preg_match('/^[\pL\pN]+$/u', $value) === 1;
    }

    public static function alphaNum($value): bool
    {
        return self::alphanumeric($value);
    }

    public static function slug($value): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1;
    }

    public static function uuid($value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    public static function ip($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    public static function ipv4($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public static function ipv6($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    public static function json($value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function date($value, string $format = 'Y-m-d'): bool
    {
        $parsed = \DateTime::createFromFormat($format, $value);
        return $parsed && $parsed->format($format) === $value;
    }

    public static function phone($value): bool
    {
        return preg_match('/^\+?[1-9]\d{1,14}$/', $value) === 1;
    }

    public static function creditCard($value): bool
    {
        $cleaned = preg_replace('/\D/', '', $value);

        if (strlen($cleaned) < 13 || strlen($cleaned) > 19) {
            return false;
        }

        $sum = 0;
        $isEven = false;

        for ($i = strlen($cleaned) - 1; $i >= 0; $i--) {
            $digit = (int) $cleaned[$i];

            if ($isEven) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $isEven = !$isEven;
        }

        return $sum % 10 === 0;
    }
}
