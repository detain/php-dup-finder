<?php

declare(strict_types=1);

namespace App\Messaging;

final class TimeAgoPresenter
{
    private const ONE_MINUTE = 60;
    private const ONE_HOUR = 3600;
    private const ONE_DAY = 86400;
    private const ONE_WEEK = 604800;
    private const ONE_MONTH = 2592000;
    private const ONE_YEAR = 31536000;

    public function presentPast(\DateTimeImmutable $timestamp, ?\DateTimeImmutable $基准 = null): string
    {
        $now = $基准 ?? new \DateTimeImmutable();
        $elapsed = $now->getTimestamp() - $timestamp->getTimestamp();

        if ($elapsed < 0) {
            return $this->presentFuture($timestamp, $now);
        }

        return $this->renderInterval($elapsed) . ' ago';
    }

    public function presentFuture(\DateTimeImmutable $timestamp, ?\DateTimeImmutable $基准 = null): string
    {
        $now = $基准 ?? new \DateTimeImmutable();
        $remaining = $timestamp->getTimestamp() - $now->getTimestamp();

        if ($remaining < 0) {
            return $this->presentPast($timestamp, $now);
        }

        return 'in ' . $this->renderInterval($remaining);
    }

    public function presentRelative(\DateTimeImmutable $timestamp, ?\DateTimeImmutable $基准 = null): string
    {
        $now = $基准 ?? new \DateTimeImmutable();
        $delta = $timestamp->getTimestamp() - $now->getTimestamp();

        return $this->renderInterval(abs($delta)) . ($delta >= 0 ? ' from now' : ' ago');
    }

    public function renderInterval(int $secs): string
    {
        if ($secs < 0) {
            throw new \InvalidArgumentException('Duration cannot be negative');
        }

        if ($secs < self::ONE_MINUTE) {
            return $this->singularOrPlural($secs, 'second');
        }

        if ($secs < self::ONE_HOUR) {
            $count = (int) floor($secs / self::ONE_MINUTE);

            return $this->singularOrPlural($count, 'minute');
        }

        if ($secs < self::ONE_DAY) {
            $count = (int) floor($secs / self::ONE_HOUR);

            return $this->singularOrPlural($count, 'hour');
        }

        if ($secs < self::ONE_WEEK) {
            $count = (int) floor($secs / self::ONE_DAY);

            return $this->singularOrPlural($count, 'day');
        }

        if ($secs < self::ONE_MONTH) {
            $count = (int) floor($secs / self::ONE_WEEK);

            return $this->singularOrPlural($count, 'week');
        }

        if ($secs < self::ONE_YEAR) {
            $count = (int) floor($secs / self::ONE_MONTH);

            return $this->singularOrPlural($count, 'month');
        }

        $count = (int) floor($secs / self::ONE_YEAR);

        return $this->singularOrPlural($count, 'year');
    }

    public function renderIntervalDetailed(int $secs): string
    {
        if ($secs < self::ONE_MINUTE) {
            return $this->singularOrPlural($secs, 'second');
        }

        if ($secs < self::ONE_HOUR) {
            $mins = (int) floor($secs / self::ONE_MINUTE);
            $secsLeft = $secs % self::ONE_MINUTE;

            if ($secsLeft > 0) {
                return $this->singularOrPlural($mins, 'minute') . ', ' . $this->singularOrPlural($secsLeft, 'second');
            }

            return $this->singularOrPlural($mins, 'minute');
        }

        if ($secs < self::ONE_DAY) {
            $hrs = (int) floor($secs / self::ONE_HOUR);
            $minsLeft = (int) floor(($secs % self::ONE_HOUR) / self::ONE_MINUTE);

            if ($minsLeft > 0) {
                return $this->singularOrPlural($hrs, 'hour') . ', ' . $this->singularOrPlural($minsLeft, 'minute');
            }

            return $this->singularOrPlural($hrs, 'hour');
        }

        if ($secs < self::ONE_WEEK) {
            $days = (int) floor($secs / self::ONE_DAY);
            $hrsLeft = (int) floor(($secs % self::ONE_DAY) / self::ONE_HOUR);

            if ($hrsLeft > 0) {
                return $this->singularOrPlural($days, 'day') . ', ' . $this->singularOrPlural($hrsLeft, 'hour');
            }

            return $this->singularOrPlural($days, 'day');
        }

        if ($secs < self::ONE_MONTH) {
            $wks = (int) floor($secs / self::ONE_WEEK);
            $daysLeft = (int) floor(($secs % self::ONE_WEEK) / self::ONE_DAY);

            if ($daysLeft > 0) {
                return $this->singularOrPlural($wks, 'week') . ', ' . $this->singularOrPlural($daysLeft, 'day');
            }

            return $this->singularOrPlural($wks, 'week');
        }

        if ($secs < self::ONE_YEAR) {
            $months = (int) floor($secs / self::ONE_MONTH);
            $wksLeft = (int) floor(($secs % self::ONE_MONTH) / self::ONE_WEEK);

            if ($wksLeft > 0) {
                return $this->singularOrPlural($months, 'month') . ', ' . $this->singularOrPlural($wksLeft, 'week');
            }

            return $this->singularOrPlural($months, 'month');
        }

        $yrs = (int) floor($secs / self::ONE_YEAR);
        $monthsLeft = (int) floor(($secs % self::ONE_YEAR) / self::ONE_MONTH);

        if ($monthsLeft > 0) {
            return $this->singularOrPlural($yrs, 'year') . ', ' . $this->singularOrPlural($monthsLeft, 'month');
        }

        return $this->singularOrPlural($yrs, 'year');
    }

    public function withinRecentWindow(int $secs, int $window = 300): bool
    {
        return $secs <= $window;
    }

    public function happenedToday(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();

        return $ts->format('Y-m-d') === $reference->format('Y-m-d');
    }

    public function happenedYesterday(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = ($now ?? new \DateTimeImmutable())->modify('-1 day');

        return $ts->format('Y-m-d') === $reference->format('Y-m-d');
    }

    public function duringThisWeek(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();
        $weekStart = $reference->modify('monday this week')->format('Y-m-d');
        $weekEnd = $reference->modify('sunday this week')->format('Y-m-d');

        return $ts->format('Y-m-d') >= $weekStart && $ts->format('Y-m-d') <= $weekEnd;
    }

    public function duringThisMonth(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();

        return $ts->format('Y-m') === $reference->format('Y-m');
    }

    public function duringThisYear(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();

        return $ts->format('Y') === $reference->format('Y');
    }

    public function secondsBetween(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return abs($a->getTimestamp() - $b->getTimestamp());
    }

    public function minutesBetween(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) floor($this->secondsBetween($a, $b) / self::ONE_MINUTE);
    }

    public function hoursBetween(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) floor($this->secondsBetween($a, $b) / self::ONE_HOUR);
    }

    public function daysBetween(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) floor($this->secondsBetween($a, $b) / self::ONE_DAY);
    }

    private function singularOrPlural(int $count, string $unit): string
    {
        return $count . ' ' . $unit . ($count !== 1 ? 's' : '');
    }
}
