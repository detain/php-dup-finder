<?php

declare(strict_types=1);

namespace App\Social;

final class RelativeTimeFormatter
{
    private const SECOND = 1;
    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86400;
    private const WEEK = 604800;
    private const MONTH = 2592000;
    private const YEAR = 31536000;

    public function formatPast(\DateTimeImmutable $past, ?\DateTimeImmutable $now = null): string
    {
        $reference = $now ?? new \DateTimeImmutable();
        $diff = $reference->getTimestamp() - $past->getTimestamp();

        if ($diff < 0) {
            return $this->formatFuture($past, $reference);
        }

        return $this->describeInterval($diff) . ' ago';
    }

    public function formatFuture(\DateTimeImmutable $future, ?\DateTimeImmutable $now = null): string
    {
        $reference = $now ?? new \DateTimeImmutable();
        $diff = $future->getTimestamp() - $reference->getTimestamp();

        if ($diff < 0) {
            return $this->formatPast($future, $reference);
        }

        return 'in ' . $this->describeInterval($diff);
    }

    public function formatRelative(\DateTimeImmutable $date, ?\DateTimeImmutable $now = null): string
    {
        $reference = $now ?? new \DateTimeImmutable();
        $diff = $date->getTimestamp() - $reference->getTimestamp();

        return $this->describeInterval(abs($diff)) . ($diff >= 0 ? ' from now' : ' ago');
    }

    public function describeInterval(int $seconds): string
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Seconds cannot be negative');
        }

        if ($seconds < self::MINUTE) {
            return $this->pluralize($seconds, 'second');
        }

        if ($seconds < self::HOUR) {
            $minutes = (int) floor($seconds / self::MINUTE);

            return $this->pluralize($minutes, 'minute');
        }

        if ($seconds < self::DAY) {
            $hours = (int) floor($seconds / self::HOUR);

            return $this->pluralize($hours, 'hour');
        }

        if ($seconds < self::WEEK) {
            $days = (int) floor($seconds / self::DAY);

            return $this->pluralize($days, 'day');
        }

        if ($seconds < self::MONTH) {
            $weeks = (int) floor($seconds / self::WEEK);

            return $this->pluralize($weeks, 'week');
        }

        if ($seconds < self::YEAR) {
            $months = (int) floor($seconds / self::MONTH);

            return $this->pluralize($months, 'month');
        }

        $years = (int) floor($seconds / self::YEAR);

        return $this->pluralize($years, 'year');
    }

    public function describeIntervalVerbose(int $seconds): string
    {
        if ($seconds < self::MINUTE) {
            return $this->pluralize($seconds, 'second');
        }

        if ($seconds < self::HOUR) {
            $minutes = (int) floor($seconds / self::MINUTE);
            $remainingSeconds = $seconds % self::MINUTE;

            if ($remainingSeconds > 0) {
                return $this->pluralize($minutes, 'minute') . ', ' . $this->pluralize($remainingSeconds, 'second');
            }

            return $this->pluralize($minutes, 'minute');
        }

        if ($seconds < self::DAY) {
            $hours = (int) floor($seconds / self::HOUR);
            $remainingMinutes = (int) floor(($seconds % self::HOUR) / self::MINUTE);

            if ($remainingMinutes > 0) {
                return $this->pluralize($hours, 'hour') . ', ' . $this->pluralize($remainingMinutes, 'minute');
            }

            return $this->pluralize($hours, 'hour');
        }

        if ($seconds < self::WEEK) {
            $days = (int) floor($seconds / self::DAY);
            $remainingHours = (int) floor(($seconds % self::DAY) / self::HOUR);

            if ($remainingHours > 0) {
                return $this->pluralize($days, 'day') . ', ' . $this->pluralize($remainingHours, 'hour');
            }

            return $this->pluralize($days, 'day');
        }

        if ($seconds < self::MONTH) {
            $weeks = (int) floor($seconds / self::WEEK);
            $remainingDays = (int) floor(($seconds % self::WEEK) / self::DAY);

            if ($remainingDays > 0) {
                return $this->pluralize($weeks, 'week') . ', ' . $this->pluralize($remainingDays, 'day');
            }

            return $this->pluralize($weeks, 'week');
        }

        if ($seconds < self::YEAR) {
            $months = (int) floor($seconds / self::MONTH);
            $remainingWeeks = (int) floor(($seconds % self::MONTH) / self::WEEK);

            if ($remainingWeeks > 0) {
                return $this->pluralize($months, 'month') . ', ' . $this->pluralize($remainingWeeks, 'week');
            }

            return $this->pluralize($months, 'month');
        }

        $years = (int) floor($seconds / self::YEAR);
        $remainingMonths = (int) floor(($seconds % self::YEAR) / self::MONTH);

        if ($remainingMonths > 0) {
            return $this->pluralize($years, 'year') . ', ' . $this->pluralize($remainingMonths, 'month');
        }

        return $this->pluralize($years, 'year');
    }

    public function isRecent(int $seconds, int $threshold = 300): bool
    {
        return $seconds <= $threshold;
    }

    public function isToday(\DateTimeImmutable $date, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();

        return $date->format('Y-m-d') === $reference->format('Y-m-d');
    }

    public function isYesterday(\DateTimeImmutable $date, ?\DateTimeImmutable $now = null): bool
    {
        $reference = ($now ?? new \DateTimeImmutable())->modify('-1 day');

        return $date->format('Y-m-d') === $reference->format('Y-m-d');
    }

    public function isThisWeek(\DateTimeImmutable $date, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();
        $weekStart = $reference->modify('monday this week')->format('Y-m-d');
        $weekEnd = $reference->modify('sunday this week')->format('Y-m-d');

        return $date->format('Y-m-d') >= $weekStart && $date->format('Y-m-d') <= $weekEnd;
    }

    public function isThisMonth(\DateTimeImmutable $date, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();

        return $date->format('Y-m') === $reference->format('Y-m');
    }

    public function isThisYear(\DateTimeImmutable $date, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();

        return $date->format('Y') === $reference->format('Y');
    }

    public function getDiffInSeconds(\DateTimeImmutable $date1, \DateTimeImmutable $date2): int
    {
        return abs($date1->getTimestamp() - $date2->getTimestamp());
    }

    public function getDiffInMinutes(\DateTimeImmutable $date1, \DateTimeImmutable $date2): int
    {
        return (int) floor($this->getDiffInSeconds($date1, $date2) / self::MINUTE);
    }

    public function getDiffInHours(\DateTimeImmutable $date1, \DateTimeImmutable $date2): int
    {
        return (int) floor($this->getDiffInSeconds($date1, $date2) / self::HOUR);
    }

    public function getDiffInDays(\DateTimeImmutable $date1, \DateTimeImmutable $date2): int
    {
        return (int) floor($this->getDiffInSeconds($date1, $date2) / self::DAY);
    }

    private function pluralize(int $count, string $unit): string
    {
        return $count . ' ' . $unit . ($count !== 1 ? 's' : '');
    }
}
