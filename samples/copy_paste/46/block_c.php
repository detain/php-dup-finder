<?php

declare(strict_types=1);

namespace App\Notifications;

final class HumanTimeFormatter
{
    private const SEC = 1;
    private const MIN = 60;
    private const HR = 3600;
    private const DY = 86400;
    private const WK = 604800;
    private const MO = 2592000;
    private const YR = 31536000;

    public function ago(\DateTimeImmutable $past, ?\DateTimeImmutable $pivot = null): string
    {
        $now = $pivot ?? new \DateTimeImmutable();
        $gap = $now->getTimestamp() - $past->getTimestamp();

        if ($gap < 0) {
            return $this->ahead($past, $now);
        }

        return $this->span($gap) . ' ago';
    }

    public function ahead(\DateTimeImmutable $future, ?\DateTimeImmutable $pivot = null): string
    {
        $now = $pivot ?? new \DateTimeImmutable();
        $gap = $future->getTimestamp() - $now->getTimestamp();

        if ($gap < 0) {
            return $this->ago($future, $now);
        }

        return 'in ' . $this->span($gap);
    }

    public function relative(\DateTimeImmutable $ts, ?\DateTimeImmutable $pivot = null): string
    {
        $now = $pivot ?? new \DateTimeImmutable();
        $delta = $ts->getTimestamp() - $now->getTimestamp();

        return $this->span(abs($delta)) . ($delta >= 0 ? ' from now' : ' ago');
    }

    public function span(int $seconds): string
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Duration must be non-negative');
        }

        if ($seconds < self::MIN) {
            return $this->word($seconds, 'second');
        }

        if ($seconds < self::HR) {
            $val = (int) floor($seconds / self::MIN);

            return $this->word($val, 'minute');
        }

        if ($seconds < self::DY) {
            $val = (int) floor($seconds / self::HR);

            return $this->word($val, 'hour');
        }

        if ($seconds < self::WK) {
            $val = (int) floor($seconds / self::DY);

            return $this->word($val, 'day');
        }

        if ($seconds < self::MO) {
            $val = (int) floor($seconds / self::WK);

            return $this->word($val, 'week');
        }

        if ($seconds < self::YR) {
            $val = (int) floor($seconds / self::MO);

            return $this->word($val, 'month');
        }

        $val = (int) floor($seconds / self::YR);

        return $this->word($val, 'year');
    }

    public function spanDetailed(int $seconds): string
    {
        if ($seconds < self::MIN) {
            return $this->word($seconds, 'second');
        }

        if ($seconds < self::HR) {
            $m = (int) floor($seconds / self::MIN);
            $s = $seconds % self::MIN;

            if ($s > 0) {
                return $this->word($m, 'minute') . ', ' . $this->word($s, 'second');
            }

            return $this->word($m, 'minute');
        }

        if ($seconds < self::DY) {
            $h = (int) floor($seconds / self::HR);
            $m = (int) floor(($seconds % self::HR) / self::MIN);

            if ($m > 0) {
                return $this->word($h, 'hour') . ', ' . $this->word($m, 'minute');
            }

            return $this->word($h, 'hour');
        }

        if ($seconds < self::WK) {
            $d = (int) floor($seconds / self::DY);
            $h = (int) floor(($seconds % self::DY) / self::HR);

            if ($h > 0) {
                return $this->word($d, 'day') . ', ' . $this->word($h, 'hour');
            }

            return $this->word($d, 'day');
        }

        if ($seconds < self::MO) {
            $w = (int) floor($seconds / self::WK);
            $d = (int) floor(($seconds % self::WK) / self::DY);

            if ($d > 0) {
                return $this->word($w, 'week') . ', ' . $this->word($d, 'day');
            }

            return $this->word($w, 'week');
        }

        if ($seconds < self::YR) {
            $mo = (int) floor($seconds / self::MO);
            $w = (int) floor(($seconds % self::MO) / self::WK);

            if ($w > 0) {
                return $this->word($mo, 'month') . ', ' . $this->word($w, 'week');
            }

            return $this->word($mo, 'month');
        }

        $yr = (int) floor($seconds / self::YR);
        $mo = (int) floor(($seconds % self::YR) / self::MO);

        if ($mo > 0) {
            return $this->word($yr, 'year') . ', ' . $this->word($mo, 'month');
        }

        return $this->word($yr, 'year');
    }

    public function isRecent(int $seconds, int $threshold = 300): bool
    {
        return $seconds <= $threshold;
    }

    public function isToday(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();

        return $ts->format('Y-m-d') === $reference->format('Y-m-d');
    }

    public function isYesterdayTime(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = ($now ?? new \DateTimeImmutable())->modify('-1 day');

        return $ts->format('Y-m-d') === $reference->format('Y-m-d');
    }

    public function isThisWeekTime(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();
        $weekStart = $reference->modify('monday this week')->format('Y-m-d');
        $weekEnd = $reference->modify('sunday this week')->format('Y-m-d');

        return $ts->format('Y-m-d') >= $weekStart && $ts->format('Y-m-d') <= $weekEnd;
    }

    public function isThisMonthTime(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();

        return $ts->format('Y-m') === $reference->format('Y-m');
    }

    public function isThisYearTime(\DateTimeImmutable $ts, ?\DateTimeImmutable $now = null): bool
    {
        $reference = $now ?? new \DateTimeImmutable();

        return $ts->format('Y') === $reference->format('Y');
    }

    public function diffSeconds(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return abs($a->getTimestamp() - $b->getTimestamp());
    }

    public function diffMinutes(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) floor($this->diffSeconds($a, $b) / self::MIN);
    }

    public function diffHours(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) floor($this->diffSeconds($a, $b) / self::HR);
    }

    public function diffDays(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) floor($this->diffSeconds($a, $b) / self::DY);
    }

    private function word(int $count, string $unit): string
    {
        return $count . ' ' . $unit . ($count !== 1 ? 's' : '');
    }
}
