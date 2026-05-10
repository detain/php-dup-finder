<?php
declare(strict_types=1);

namespace Phpdup\Ml;

use JsonException;
use Phpdup\Extraction\Block;

/**
 * HTTP client for an external **pair-similarity** ML scoring
 * service (option 6 of `docs/plans/orm-db-semantic-dedup.md`).
 *
 * Companion to {@see MlClient}, which scores cluster *safety*.
 * MlPairClient scores the **similarity** of a single block pair
 * against a model trained on a labelled corpus that includes
 * ORM ↔ raw-SQL examples. The model gets the feature vector
 * produced by {@see PairFeatures} and returns a single score in
 * `[0, 1]`.
 *
 * **Wire contract**
 *
 *   POST /score-pair
 *   Content-Type: application/json
 *
 *   {
 *     "feature_version":       1,
 *     "structural_hash_match": false,
 *     "ngram_jaccard":         0.42,
 *     "var_jaccard":           0.55,
 *     "call_jaccard":          0.10,
 *     "return_jaccard":        1.00,
 *     "db_tag_jaccard":        1.00,
 *     "ir_token_jaccard":      0.93,
 *     "block_size_ratio":      0.85,
 *     "kind_match":            true,
 *     "block_a_kind":          "method",
 *     "block_b_kind":          "method"
 *   }
 *
 *   200 OK
 *   { "similarity": 0.91, "confidence": 0.78 }
 *
 * **Failure mode**
 *
 * Returns null on any transport error or malformed response so
 * callers fall back to the AST-level scoring tiers without code-
 * path branching. This is the same risk-mitigation pattern as the
 * IR lifter: when the ML service is unavailable, phpdup keeps
 * working with reduced precision rather than failing the run.
 *
 * **Security**
 *
 * Reuses the same SSRF-hardened URL allow-list as
 * {@see MlClient::isAllowedUrl()} — http(s) only, well-formed
 * host, no `0.0.0.0`. Callers wanting tighter policy should
 * prefilter the URL.
 */
final class MlPairClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSec = 5,
        private readonly PairFeatures $features = new PairFeatures(),
    ) {
    }

    /**
     * Score a `(A, B)` block pair via the remote model.
     *
     * @return array{similarity: float, confidence: float}|null Null when
     *         disabled, the URL is rejected, or the service returns
     *         malformed data.
     */
    public function score(Block $a, Block $b): ?array
    {
        if ($this->baseUrl === '') {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/score-pair';
        if (!MlClient::isAllowedUrl($url)) {
            return null;
        }

        try {
            $body = json_encode($this->features->extract($a, $b), JSON_THROW_ON_ERROR);
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
        $similarity = isset($decoded['similarity']) ? (float)$decoded['similarity'] : null;
        $confidence = isset($decoded['confidence']) ? (float)$decoded['confidence'] : null;
        if ($similarity === null || $confidence === null) {
            return null;
        }
        return ['similarity' => $similarity, 'confidence' => $confidence];
    }

    /**
     * @return string|null Raw response body, or null on transport error.
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
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ]);
                $resp = curl_exec($ch);
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
                'method'          => 'POST',
                'header'          => "Content-Type: application/json\r\n",
                'content'         => $body,
                'timeout'         => $this->timeoutSec,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        return $resp === false ? null : $resp;
    }
}
