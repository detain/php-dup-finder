<?php

declare(strict_types=1);

namespace App\Helpers;

class DateTimeHelper
{
    public static function formatDateTime(\DateTimeInterface $date, string $format = 'Y-m-d H:i:s'): string
    {
        return $date->format($format);
    }

    public static function formatDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public static function formatTime(\DateTimeInterface $date): string
    {
        return $date->format('H:i:s');
    }

    public static function formatDateForDisplay(\DateTimeInterface $date): string
    {
        return $date->format('M j, Y');
    }

    public static function formatDateTimeForDisplay(\DateTimeInterface $date): string
    {
        return $date->format('M j, Y g:i A');
    }

    public static function formatTimeForDisplay(\DateTimeInterface $date): string
    {
        return $date->format('g:i A');
    }

    public static function formatRelative(\DateTimeInterface $date): string
    {
        $now = new \DateTimeImmutable();
        $diff = $now->diff($date);

        if ($diff->y > 0) {
            return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        }

        if ($diff->m > 0) {
            return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        }

        if ($diff->d > 0) {
            return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        }

        if ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        }

        if ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        }

        return 'just now';
    }

    public static function formatTimezone(\DateTimeInterface $date, string $timezone): string
    {
        $date->setTimezone(new \DateTimeZone($timezone));
        return $date->format('Y-m-d H:i:s T');
    }

    public static function formatIso8601(\DateTimeInterface $date): string
    {
        return $date->format(\DateTimeInterface::ATOM);
    }

    public static function formatRfc7231(\DateTimeInterface $date): string
    {
        return $date->format('D, d M Y H:i:s \G\M\T');
    }

    public static function parseDate(string $date, string $format = 'Y-m-d'): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat($format, $date);
        return $parsed ?: null;
    }

    public static function parseDateTime(string $date, string $format = 'Y-m-d H:i:s'): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat($format, $date);
        return $parsed ?: null;
    }

    public static function startOfDay(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $date->format('Y-m-d') . ' 00:00:00'
        );
    }

    public static function endOfDay(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $date->format('Y-m-d') . ' 23:59:59'
        );
    }

    public static function startOfMonth(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $date->format('Y-m') . '-01 00:00:00'
        );
    }

    public static function endOfMonth(\DateTimeInterface $date): \DateTimeImmutable
    {
        $lastDay = $date->format('t');
        return \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $date->format('Y-m') . "-{$lastDay} 23:59:59"
        );
    }
}
