<?php

namespace App\Services\Api;

final class VersionConfig
{
    public readonly string $defaultVersion;
    public readonly array $supportedVersions;

    public function __construct(string $defaultVersion = '1.0', array $supportedVersions = [])
    {
        $this->defaultVersion = $defaultVersion;
        $this->supportedVersions = $supportedVersions;
    }
}

final class VersionService
{
    private VersionConfig $config;

    public function __construct(VersionConfig $config)
    {
        $this->config = $config;
    }

    public function detect(array $headers, string $path, ?string $queryString): string
    {
        if (isset($headers['Accept'])) {
            $version = $this->extractFromHeader($headers['Accept']);

            if ($version !== null) {
                return $version;
            }
        }

        if ($version = $this->extractFromPath($path)) {
            return $version;
        }

        if ($queryString && $version = $this->extractFromQuery($queryString)) {
            return $version;
        }

        return $this->config->defaultVersion;
    }

    public function compare(string $a, string $b): int
    {
        $partsA = explode('.', $a);
        $partsB = explode('.', $b);

        $majorDiff = (int) $partsA[0] <=> (int) $partsB[0];

        return $majorDiff !== 0 ? $majorDiff : ((int) $partsA[1] <=> (int) $partsB[1]);
    }

    public function isCompatible(string $client, string $server): bool
    {
        return explode('.', $client)[0] === explode('.', $server)[0];
    }

    public function findBestMatch(array $clientVersions, array $serverVersions): ?string
    {
        $exactMatch = array_intersect($clientVersions, $serverVersions);

        if (!empty($exactMatch)) {
            usort($exactMatch, fn($a, $b) => $this->compare($b, $a));

            return $exactMatch[0];
        }

        $compatibleServer = array_filter(
            $serverVersions,
            fn($v) => $this->isCompatible($v, $clientVersions[0] ?? '0.0')
        );

        if (!empty($compatibleServer)) {
            usort($compatibleServer, fn($a, $b) => $this->compare($b, $a));

            return $compatibleServer[0];
        }

        return null;
    }

    private function extractFromHeader(string $header): ?string
    {
        if (preg_match('/version=["\']?(\d+\.\d+)/i', $header, $m)) {
            return $m[1];
        }

        if (preg_match('/application\/vnd\.api\+json;\s*version=(\d+\.\d+)/i', $header, $m)) {
            return $m[1];
        }

        if (preg_match('/v(\d+(?:\.\d+)?)/i', $header, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractFromPath(string $path): ?string
    {
        if (preg_match('#/v(\d+(?:\.\d+)?)/#', $path, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractFromQuery(string $query): ?string
    {
        parse_str($query, $params);

        if (isset($params['api_version']) && preg_match('/^\d+\.\d+$/', $params['api_version'])) {
            return $params['api_version'];
        }

        if (isset($params['version']) && preg_match('/^\d+\.\d+$/', $params['version'])) {
            return $params['version'];
        }

        return null;
    }
}
