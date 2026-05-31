<?php
declare(strict_types=1);

namespace Acme\Cache\Catalog;

final class CatalogCache
{
    /**
     * Build a deterministic cache key for a domain entity.
     *
     * @param string                $domain logical bucket
     * @param array<string,mixed>   $parts  key components
     */
    public function makeKey(string $domain, array $parts): string
    {
        ksort($parts);
        $segments = [];
        foreach ($parts as $name => $value) {
            $flat = is_scalar($value) ? (string) $value : json_encode($value);
            $flat = strtolower(trim((string) $flat));
            $flat = preg_replace('/[^a-z0-9_]+/', '_', $flat);
            $segments[] = $name . '=' . $flat;
        }
        $joined = implode('&', $segments);
        $hash = substr(sha1($joined), 0, 16);
        $key = 'v1:' . $domain . ':' . $hash;
        return $key;
    }

    public function get(string $key): ?array
    {
        return null;
    }
}
