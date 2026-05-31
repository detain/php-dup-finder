<?php

declare(strict_types=1);

namespace App\Http;

final class VersionResolver
{
    public function extractFromAccept(string $header): ?string
    {
        if (preg_match('/version=["\']?(\d+\.\d+)/i', $header, $matches)) {
            return $matches[1];
        }

        if (preg_match('/application\/vnd\.api\+json;\s*version=(\d+\.\d+)/i', $header, $matches)) {
            return $matches[1];
        }

        if (preg_match('/v(\d+(?:\.\d+)?)/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function extractFromPath(string $uri): ?string
    {
        if (preg_match('#/v(\d+(?:\.\d+)?)/#', $uri, $matches)) {
            return $matches[1];
        }

        if (preg_match('#/api/v(\d+(?:\.\d+)?)/#', $uri, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function extractFromQueryString(string $query): ?string
    {
        parse_str($query, $values);

        if (isset($values['api_version']) && $this->isVersionFormatValid($values['api_version'])) {
            return $values['api_version'];
        }

        if (isset($values['version']) && $this->isVersionFormatValid($values['version'])) {
            return $values['version'];
        }

        return null;
    }

    public function resolve(array $requestHeaders, string $path, ?string $query = null): ?string
    {
        if (isset($requestHeaders['Accept']) && $ver = $this->extractFromAccept($requestHeaders['Accept'])) {
            return $ver;
        }

        if ($ver = $this->extractFromPath($path)) {
            return $ver;
        }

        if ($query && $ver = $this->extractFromQueryString($query)) {
            return $ver;
        }

        return null;
    }

    public function isVersionFormatValid(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+$/', $version);
    }

    public function compare(string $a, string $b): int
    {
        $partsA = explode('.', $a);
        $partsB = explode('.', $b);

        $majorA = (int) $partsA[0];
        $majorB = (int) $partsB[0];

        if ($majorA !== $majorB) {
            return $majorA <=> $majorB;
        }

        $minorA = (int) $partsA[1];
        $minorB = (int) $partsB[1];

        return $minorA <=> $minorB;
    }

    public function areCompatible(string $client, string $server): bool
    {
        $clientMajor = (int) explode('.', $client)[0];
        $serverMajor = (int) explode('.', $server)[0];

        return $clientMajor === $serverMajor;
    }

    public function isSupportedVersion(string $version, array $supported): bool
    {
        return in_array($version, $supported, true);
    }

    public function inRange(string $version, string $min, string $max): bool
    {
        if ($this->compare($version, $min) < 0) {
            return false;
        }

        if ($this->compare($version, $max) > 0) {
            return false;
        }

        return true;
    }

    public function findBestMatch(array $client, array $server): ?string
    {
        $intersection = array_intersect($client, $server);

        if (!empty($intersection)) {
            usort($intersection, fn($a, $b) => $this->compare($b, $a));

            return $intersection[0];
        }

        $compatible = array_filter(
            $server,
            fn($v) => $this->areCompatible($v, $client[0] ?? '0.0')
        );

        if (!empty($compatible)) {
            usort($compatible, fn($a, $b) => $this->compare($b, $a));

            return $compatible[0];
        }

        return null;
    }

    public function standardize(string $version): string
    {
        $components = explode('.', $version);

        return ((int) $components[0]) . '.' . ((int) ($components[1] ?? 0));
    }

    public function bumpMajor(string $version): string
    {
        $major = (int) explode('.', $version)[0];

        return ($major + 1) . '.0';
    }

    public function bumpMinor(string $version): string
    {
        $components = explode('.', $version);
        $major = (int) $components[0];
        $minor = (int) ($components[1] ?? 0);

        return $major . '.' . ($minor + 1);
    }

    public function nextDeprecation(string $current): string
    {
        return $this->bumpMinor($current);
    }

    public function nextSunset(string $current): string
    {
        return $this->bumpMajor($current);
    }

    public function computeSunsetDate(string $sunsetVersion, \DateTimeImmutable $when): \DateTimeImmutable
    {
        return $when->modify('+12 months');
    }
}
