<?php

declare(strict_types=1);

namespace Marketing\Parse;

final class StateMachineEmailFinder
{
    public function find(string $text): ?string
    {
        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            $start = $this->findAtSign($text, $i, $len);
            if ($start === null) {
                return null;
            }

            $localStart = $this->scanLocalBackwards($text, $start);
            $domainEnd = $this->scanDomainForwards($text, $start, $len);

            if ($localStart < $start && $domainEnd > $start + 1) {
                $candidate = substr($text, $localStart, $domainEnd - $localStart);
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    return strtolower($candidate);
                }
            }

            $i = $start + 1;
        }

        return null;
    }

    private function findAtSign(string $text, int $from, int $len): ?int
    {
        for ($i = $from; $i < $len; $i++) {
            if ($text[$i] === '@') {
                return $i;
            }
        }
        return null;
    }

    private function scanLocalBackwards(string $text, int $at): int
    {
        $i = $at;
        while ($i > 0 && preg_match('/[A-Za-z0-9._%+\-]/', $text[$i - 1]) === 1) {
            $i--;
        }
        return $i;
    }

    private function scanDomainForwards(string $text, int $at, int $len): int
    {
        $i = $at + 1;
        while ($i < $len && preg_match('/[A-Za-z0-9.\-]/', $text[$i]) === 1) {
            $i++;
        }
        return $i;
    }
}
