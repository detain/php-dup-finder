<?php

declare(strict_types=1);

namespace App\Api;

final class ApiVersionDetector
{
    public function detectVersionFromHeader(string $acceptHeader): ?string
    {
        if (preg_match('/version=["\']?(\d+\.\d+)/i', $acceptHeader, $matches)) {
            return $matches[1];
        }

        if (preg_match('/application\/vnd\.api\+json;\s*version=(\d+\.\d+)/i', $acceptHeader, $matches)) {
            return $matches[1];
        }

        if (preg_match('/v(\d+\.\d+)/i', $acceptHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function detectVersionFromPath(string $path): ?string
    {
        if (preg_match('#/v(\d+(?:\.\d+)?)/#', $path, $matches)) {
            return $matches[1];
        }

        if (preg_match('#/api/v(\d+(?:\.\d+)?)/#', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function detectVersionFromQuery(string $queryString): ?string
    {
        parse_str($queryString, $params);

        if (isset($params['api_version']) && $this->isValidVersion($params['api_version'])) {
            return $params['api_version'];
        }

        if (isset($params['version']) && $this->isValidVersion($params['version'])) {
            return $params['version'];
        }

        return null;
    }

    public function detectVersion(array $headers, string $path, ?string $queryString): ?string
    {
        if (isset($headers['Accept']) && $version = $this->detectVersionFromHeader($headers['Accept'])) {
            return $version;
        }

        if ($version = $this->detectVersionFromPath($path)) {
            return $version;
        }

        if ($queryString && $version = $this->detectVersionFromQuery($queryString)) {
            return $version;
        }

        return null;
    }

    public function isValidVersion(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+$/', $version);
    }

    public function compareVersions(string $v1, string $v2): int
    {
        $parts1 = explode('.', $v1);
        $parts2 = explode('.', $v2);

        $major1 = (int) $parts1[0];
        $major2 = (int) $parts2[0];

        if ($major1 !== $major2) {
            return $major1 <=> $major2;
        }

        $minor1 = (int) $parts1[1];
        $minor2 = (int) $parts2[1];

        return $minor1 <=> $minor2;
    }

    public function isVersionCompatible(string $clientVersion, string $serverVersion): bool
    {
        $clientParts = explode('.', $clientVersion);
        $serverParts = explode('.', $serverVersion);

        $clientMajor = (int) $clientParts[0];
        $serverMajor = (int) $serverParts[0];

        return $clientMajor === $serverMajor;
    }

    public function isVersionSupported(string $version, array $supportedVersions): bool
    {
        return in_array($version, $supportedVersions, true);
    }

    public function isVersionInRange(string $version, string $minVersion, string $maxVersion): bool
    {
        if ($this->compareVersions($version, $minVersion) < 0) {
            return false;
        }

        if ($this->compareVersions($version, $maxVersion) > 0) {
            return false;
        }

        return true;
    }

    public function findBestMatch(array $clientVersions, array $serverVersions): ?string
    {
        $commonVersions = array_intersect($clientVersions, $serverVersions);

        if (!empty($commonVersions)) {
            usort($commonVersions, fn($a, $b) => $this->compareVersions($b, $a));

            return $commonVersions[0];
        }

        $compatibleServer = array_filter(
            $serverVersions,
            fn($v) => $this->isVersionCompatible($v, $clientVersions[0] ?? '0.0')
        );

        if (!empty($compatibleServer)) {
            usort($compatibleServer, fn($a, $b) => $this->compareVersions($b, $a));

            return $compatibleServer[0];
        }

        return null;
    }

    public function normalizeVersion(string $version): string
    {
        $parts = explode('.', $version);

        return ((int) $parts[0]) . '.' . ((int) ($parts[1] ?? 0));
    }

    public function incrementMajor(string $version): string
    {
        $parts = explode('.', $version);
        $newMajor = (int) $parts[0] + 1;

        return $newMajor . '.0';
    }

    public function incrementMinor(string $version): string
    {
        $parts = explode('.', $version);
        $major = (int) $parts[0];
        $newMinor = (int) ($parts[1] ?? 0) + 1;

        return $major . '.' . $newMinor;
    }

    public function getDeprecationVersion(string $currentVersion): string
    {
        return $this->incrementMinor($currentVersion);
    }

    public function getSunsetVersion(string $currentVersion): string
    {
        return $this->incrementMajor($currentVersion);
    }

    public function getSunsetDate(string $sunsetVersion, \DateTimeImmutable $announcementDate): \DateTimeImmutable
    {
        return $announcementDate->modify('+1 year');
    }
}
