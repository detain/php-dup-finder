<?php

declare(strict_types=1);

namespace App\Routing;

final class VersionNegotiator
{
    public function parseFromHeader(string $accept): ?string
    {
        if (preg_match('/version=["\']?(\d+\.\d+)/i', $accept, $captures)) {
            return $captures[1];
        }

        if (preg_match('/application\/vnd\.api\+json;\s*version=(\d+\.\d+)/i', $accept, $captures)) {
            return $captures[1];
        }

        if (preg_match('/v(\d+(?:\.\d+)?)/i', $accept, $captures)) {
            return $captures[1];
        }

        return null;
    }

    public function parseFromUrl(string $url): ?string
    {
        if (preg_match('#/v(\d+(?:\.\d+)?)/#', $url, $captures)) {
            return $captures[1];
        }

        if (preg_match('#/api/v(\d+(?:\.\d+)?)/#', $url, $captures)) {
            return $captures[1];
        }

        return null;
    }

    public function parseFromParams(string $params): ?string
    {
        parse_str($params, $parsed);

        if (isset($parsed['api_version']) && $this->isWellFormed($parsed['api_version'])) {
            return $parsed['api_version'];
        }

        if (isset($parsed['version']) && $this->isWellFormed($parsed['version'])) {
            return $parsed['version'];
        }

        return null;
    }

    public function resolveVersion(array $headers, string $routePath, ?string $qs = null): ?string
    {
        if (isset($headers['Accept']) && $v = $this->parseFromHeader($headers['Accept'])) {
            return $v;
        }

        if ($v = $this->parseFromUrl($routePath)) {
            return $v;
        }

        if ($qs && $v = $this->parseFromParams($qs)) {
            return $v;
        }

        return null;
    }

    public function isWellFormed(string $ver): bool
    {
        return (bool) preg_match('/^\d+\.\d+$/', $ver);
    }

    public function compareVersions(string $left, string $right): int
    {
        $leftParts = explode('.', $left);
        $rightParts = explode('.', $right);

        $leftMajor = (int) $leftParts[0];
        $rightMajor = (int) $rightParts[0];

        if ($leftMajor !== $rightMajor) {
            return $leftMajor <=> $rightMajor;
        }

        $leftMinor = (int) $leftParts[1];
        $rightMinor = (int) $rightParts[1];

        return $leftMinor <=> $rightMinor;
    }

    public function isCompatibleWith(string $requested, string $implemented): bool
    {
        $reqMajor = (int) explode('.', $requested)[0];
        $implMajor = (int) explode('.', $implemented)[0];

        return $reqMajor === $implMajor;
    }

    public function isAmongSupported(string $ver, array $supported): bool
    {
        return in_array($ver, $supported, true);
    }

    public function satisfiesRange(string $ver, string $lowest, string $highest): bool
    {
        if ($this->compareVersions($ver, $lowest) < 0) {
            return false;
        }

        if ($this->compareVersions($ver, $highest) > 0) {
            return false;
        }

        return true;
    }

    public function selectBestMatch(array $requestedVersions, array $availableVersions): ?string
    {
        $overlap = array_intersect($requestedVersions, $availableVersions);

        if (!empty($overlap)) {
            usort($overlap, fn($a, $b) => $this->compareVersions($b, $a));

            return $overlap[0];
        }

        $compatible = array_filter(
            $availableVersions,
            fn($v) => $this->isCompatibleWith($v, $requestedVersions[0] ?? '0.0')
        );

        if (!empty($compatible)) {
            usort($compatible, fn($a, $b) => $this->compareVersions($b, $a));

            return $compatible[0];
        }

        return null;
    }

    public function normalize(string $ver): string
    {
        $components = explode('.', $ver);

        return ((int) $components[0]) . '.' . ((int) ($components[1] ?? 0));
    }

    public function advanceMajor(string $ver): string
    {
        $major = (int) explode('.', $ver)[0];

        return ($major + 1) . '.0';
    }

    public function advanceMinor(string $ver): string
    {
        $components = explode('.', $ver);
        $major = (int) $components[0];
        $minor = (int) ($components[1] ?? 0);

        return $major . '.' . ($minor + 1);
    }

    public function successorDeprecation(string $ver): string
    {
        return $this->advanceMinor($ver);
    }

    public function successorSunset(string $ver): string
    {
        return $this->advanceMajor($ver);
    }

    public function calculateSunsetDate(string $sunsetVer, \DateTimeImmutable $announced): \DateTimeImmutable
    {
        return $announced->modify('+1 year');
    }
}
