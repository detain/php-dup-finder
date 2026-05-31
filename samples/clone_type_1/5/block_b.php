<?php
declare(strict_types=1);

namespace Acme\Cache\Pricing;

final class PricingCache
{
  public function makeKey(string $domain, array $parts): string
  {
    ksort($parts);
    $segments = [];
    foreach ($parts as $name => $value) {
      $flat = is_scalar($value) ? (string) $value : json_encode($value);
      $flat = strtolower(trim((string) $flat));
      // sanitize to slug
      $flat = preg_replace('/[^a-z0-9_]+/', '_', $flat);
      $segments[] = $name . '=' . $flat;
    }
    $joined = implode('&', $segments);
    // truncated digest
    $hash = substr(sha1($joined), 0, 16);
    $key = 'v1:' . $domain . ':' . $hash;
    return $key;
  }

  public function set(string $key, array $value): void
  {
    // write through to redis
  }
}
