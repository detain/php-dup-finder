<?php
declare(strict_types=1);

namespace Phpdup\Ml;

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
 */
final class MlClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSec = 5,
    ) {
    }

    /**
     * @return array{safety: float, anomaly: float}|null
     */
    public function score(Cluster $cluster): ?array
    {
        if ($this->baseUrl === '') return null;

        $payload = [
            'cluster_id'   => $cluster->id,
            'similarity'   => $cluster->similarity,
            'members'      => $cluster->size(),
            'holes'        => count($cluster->holes),
            'pattern_tags' => $cluster->patternTags,
        ];
        $url  = rtrim($this->baseUrl, '/') . '/score';
        $body = (string)json_encode($payload);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => $this->timeoutSec,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return null;
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) return null;
        $safety  = isset($decoded['safety'])  ? (float)$decoded['safety']  : null;
        $anomaly = isset($decoded['anomaly']) ? (float)$decoded['anomaly'] : null;
        if ($safety === null || $anomaly === null) return null;
        return ['safety' => $safety, 'anomaly' => $anomaly];
    }
}
