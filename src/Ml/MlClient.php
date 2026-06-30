<?php
declare(strict_types=1);

namespace Phpdup\Ml;

use JsonException;
use Phpdup\Clustering\Cluster;

/**
 * HTTP client for an external ML scoring service.
 *
 * The service is intentionally out-of-process — phpdup's hot path
 * stays PHP; the model lives in Python (or any sidecar) and is
 * accessed over HTTP. Phpdup ships only the contract:
 *
 *   POST /score
 *   {
 *     "cluster_id": "X53edd72b",
 *     "similarity": 0.93,
 *     "members": <int>,
 *     "holes": <int>,
 *     "pattern_tags": ["sql-builder", …]
 *   }
 *
 *   →  { "safety": 0.71, "anomaly": 0.12 }
 *
 * Falls back gracefully when the server is unreachable: returns null
 * so callers fall back to {@see \Phpdup\Reporting\SafetyScorer}.
 *
 * Security
 * --------
 * The base URL is config-supplied, so it is treated as
 * remote-controlled. {@see MlClient::isAllowedUrl()} enforces:
 *
 * - http(s) scheme only (no `file://`, `gopher://`, `ftp://`, …);
 * - well-formed URL with an explicit host;
 * - host not literally `0.0.0.0` (which Linux interprets as "every
 *   interface" and is a common SSRF target).
 *
 * Callers that need an even tighter policy (e.g. only allow specific
 * hostnames) can prefilter the URL before constructing the client.
 */
final class MlClient
{
    /**
     * @param string $baseUrl    HTTP(S) URL prefix of the scoring service. Empty = disabled.
     * @param int    $timeoutSec Total read timeout passed to the HTTP context.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSec = 5,
    ) {
    }

    /**
     * Score a cluster against the remote ML service.
     *
     * @return array{safety: float, anomaly: float}|null Null when the
     *         service is unreachable, returns malformed data, or the
     *         configured base URL fails validation.
     */
    public function score(Cluster $cluster): ?array
    {
        if ($this->baseUrl === '') {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/score';
        if (!self::isAllowedUrl($url)) {
            return null;
        }

        $payload = [
            'cluster_id'   => $cluster->id,
            'similarity'   => $cluster->similarity,
            'members'      => $cluster->size(),
            'holes'        => count($cluster->holes),
            'pattern_tags' => $cluster->patternTags,
        ];
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $resp = $this->postJson($url, $body);
        if ($resp === null) {
            return null;
        }
        try {
            $decoded = json_decode($resp, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $safety  = isset($decoded['safety'])  ? (float)$decoded['safety']  : null;
        $anomaly = isset($decoded['anomaly']) ? (float)$decoded['anomaly'] : null;
        if ($safety === null || $anomaly === null) {
            return null;
        }
        return ['safety' => $safety, 'anomaly' => $anomaly];
    }

    /**
     * Is `$url` something we are willing to fetch?
     *
     * Public so test code can validate URLs without having to
     * exercise the full HTTP path.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $host = (string)($parts['host'] ?? '');
        if ($host === '' || $host === '0.0.0.0') {
            return false;
        }
        return true;
    }

    /**
     * POST `$body` as JSON to `$url` and return the raw response body.
     *
     * Uses ext-curl when available (richer error reporting and easier
     * to harden) and falls back to streams when not. Returns null on
     * any transport-level failure or non-2xx HTTP response.
     */
    private function postJson(string $url, string $body): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            if ($ch === false) {
                return null;
            }
            try {
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $this->timeoutSec,
                    CURLOPT_CONNECTTIMEOUT => $this->timeoutSec,
                    // Refuse silly redirects to file:// / gopher:// / ftp://
                    // — only http(s) on the original request is allowed.
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ]);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode < 200 || $httpCode >= 300) {
                    return null;
                }
                if (!is_string($resp)) {
                    return null;
                }
                return $resp;
            } finally {
                curl_close($ch);
            }
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $body,
                'timeout'       => $this->timeoutSec,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp !== false) {
            $httpCode = $this->parseStatusCode($http_response_header);
            if ($httpCode < 200 || $httpCode >= 300) {
                return null;
            }
        }
        return $resp === false ? null : $resp;
    }

    /**
     * Extract HTTP status code from a $http_response_header array.
     *
     * @param array<string> $headers
     */
    private function parseStatusCode(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('/^HTTP\/[\d.]+\s+(\d{3})/', $h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }
}
