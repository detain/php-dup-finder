<?php
declare(strict_types=1);

/**
 * Reference sidecar for phpdup's ML pair-scoring tier — the server half
 * of the wire contract documented in docs/ML.md and docs/SERVER.md and
 * dialled by Phpdup\Ml\MlPairClient when --ml-pair-url is set.
 *
 * Plain stdlib PHP: no composer, no framework, single-process serial
 * accept loop. It is NOT a model — it echoes each vector's canonical
 * verdict: structural_hash_match means similarity 1.0, otherwise the
 * strongest jaccard signal carried; kind_match raises confidence. An
 * unknown feature_version or a malformed vector gets an honest null
 * verdict, which the client's [0,1] fence (MlClient::coerceUnitFloat)
 * reads as "no ML-tier edge" — the documented heuristic-fallback
 * spelling. See PairFeatures::WIRE_KEYS / FEATURE_VERSION for truth.
 *
 * Usage:
 *   php tools/ml-sidecar-example.php [--host 127.0.0.1] [--port 8090]
 *
 * phpdup itself refuses a loopback --ml-pair-url at admission until
 * plan step once-S54 lands the --ml-pair-allow-private opt-in (the
 * SSRF gate of engine-S19a/S19b rejects loopback and RFC 1918). Point
 * phpdup at a gate-allowed address, or probe this script with curl.
 */

// Mirrors Phpdup\Ml\PairFeatures::WIRE_KEYS (the 11 feature fields in
// emission order; the 12th wire key is the feature_version envelope
// marker). Copied as literals because a stdlib tool must not need the
// project autoloader.
const WIRE_KEYS = [
    'structural_hash_match', 'ngram_jaccard', 'var_jaccard',
    'call_jaccard', 'return_jaccard', 'db_tag_jaccard',
    'ir_token_jaccard', 'block_size_ratio', 'kind_match',
    'block_a_kind', 'block_b_kind',
];
const FEATURE_VERSION = 1;
const MAX_BODY_BYTES  = 1_048_576; // the same cap MlClient puts on responses

$host = '127.0.0.1';
$port = 8090;
for ($i = 1; $i < $argc - 1; $i++) {
    if ($argv[$i] === '--host') {
        $host = (string)$argv[++$i];
    } elseif ($argv[$i] === '--port') {
        $port = (int)$argv[++$i];
    }
}
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "ml-sidecar-example: listen failed ({$errno}): {$errstr}\n");
    exit(1);
}
fwrite(STDERR, "ml-sidecar-example: listening on http://{$host}:{$port}\n");
while (($client = @stream_socket_accept($server, -1)) !== false) {
    handle($client);
    fclose($client);
}

/** @param resource $client */
function handle($client): void
{
    stream_set_timeout($client, 5);
    $line = fgets($client);
    if ($line === false || !preg_match('#^POST (/score-pair|/score-batch) HTTP/1\.[01]$#', trim($line), $m)) {
        respond($client, 404, '{"error":"unknown route"}');
        return;
    }
    $length = 0;
    while (($h = fgets($client)) !== false && trim($h) !== '') {
        if (preg_match('/^Content-Length:\s*(\d+)$/i', trim($h), $c)) {
            $length = (int)$c[1];
        }
    }
    if ($length < 1 || $length > MAX_BODY_BYTES) {
        respond($client, 413, '{"error":"body bound"}');
        return;
    }
    $decoded = json_decode((string)stream_get_contents($client, $length), true);
    if ($m[1] === '/score-pair') {
        if (!is_array($decoded)) {
            respond($client, 400, '{"error":"malformed JSON"}');
            return;
        }
        respond($client, 200, (string)json_encode(verdict($decoded)));
        return;
    }
    $pairs = is_array($decoded) ? ($decoded['pairs'] ?? null) : null;
    if (!is_array($pairs) || !array_is_list($pairs)) {
        respond($client, 400, '{"error":"pairs must be a JSON list"}');
        return;
    }
    $results = [];
    foreach ($pairs as $v) {                    // entry i answers pair i
        $results[] = is_array($v) ? verdict($v) : null;
    }
    respond($client, 200, (string)json_encode(['results' => $results]));
}

/** @return array{similarity: float, confidence: float}|null */
function verdict(mixed $v): ?array
{
    /** @var array<string, mixed> $v */
    if (($v['feature_version'] ?? null) !== FEATURE_VERSION) {
        return null;                            // drop: version mismatch
    }
    foreach (WIRE_KEYS as $k) {
        if (!array_key_exists($k, $v)) {
            return null;                        // drop: short vector
        }
    }
    $num = static fn(string $k): float => is_numeric($v[$k]) ? max(0.0, min(1.0, (float)$v[$k])) : 0.0;
    $similarity = $v['structural_hash_match'] === true
        ? 1.0
        : max($num('ngram_jaccard'), $num('ir_token_jaccard'), $num('db_tag_jaccard'));
    return ['similarity' => round($similarity, 4), 'confidence' => $v['kind_match'] === true ? 0.9 : 0.4];
}

/** @param resource $client */
function respond($client, int $status, string $json): void
{
    $reason = [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 413 => 'Payload Too Large'][$status];
    $head   = "HTTP/1.1 {$status} {$reason}\r\n"
        . "Content-Type: application/json\r\n"
        . 'Content-Length: ' . strlen($json) . "\r\n"
        . "Connection: close\r\n\r\n";
    fwrite($client, $head . $json);
}
