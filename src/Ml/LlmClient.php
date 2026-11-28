<?php

declare(strict_types=1);

namespace Phpdup\Ml;

use Phpdup\Util\Http;

/**
 * Item 8.2 — generative transport for `phpdup audit`. The skeleton rides
 * the ml-pair machinery this repo already trusts for SSRF: admission
 * reuses MlPairClient::pairUrlAdmissionError() relabelled to the llm_*
 * key names, and every dial uses the pinned pairEndpointDecision() path
 * (DNS-checked once, addresses pinned into the transport, curl-then-
 * stream like the other clients).
 *
 * Posture rulings this class encodes (info-doc §5c / PLAN):
 *  - the auth token rides ONLY the PHPDUP_LLM_TOKEN env var — it never
 *    enters Config, a report, or any doc, because JsonReporter embeds
 *    the config section into shipped artifacts (secrets-in-artifact law);
 *  - null-on-failure everywhere: a refused address, a dead endpoint, a
 *    torn connection or a non-conforming reply all collapse to null and
 *    the audit run ships UN-ANNOTATED with a note — judgment is an
 *    enrichment, never a dependency;
 *  - responses are shape-guarded per row; partial garbage loses only the
 *    garbage rows (each verdict is independently trustworthy);
 *  - this is a one-shot CLI transport, so the ml-tier degraded-probe /
 *    backoff economics are deliberately absent (audit dials once per
 *    batch; there is no per-pair loop to protect).
 */
final class LlmClient
{
    /** Env var carrying the bearer token (the ONLY token surface). */
    public const TOKEN_ENV = 'PHPDUP_LLM_TOKEN';

    /**
     * Response cap. Own constant rather than MlClient::MAX_RESPONSE_BYTES
     * (1 MiB): a batch of verdicts for 20 packs — each with a proposal
     * paragraph — legitimately crosses 1 MiB, and truncation would look
     * like a transport failure. 4 MiB bounds a hostile endpoint's memory
     * while fitting real batches with room.
     */
    public const MAX_RESPONSE_BYTES = 4_194_304;

    public const DEFAULT_TIMEOUT_SEC = 15;

    /** Hard ceiling on packs per request — the §8.2 letter's batching. */
    public const BATCH_SIZE = 20;

    /** @var (callable(string, string, list<string>): ?string)|null */
    private $transport;

    /** @var null|array{host: string, port: int, addresses: list<string>} */
    private ?array $decision = null;
    private bool $decisionResolved = false;

    /**
     * @param (callable(string $url, string $body, list<string> $headers): ?string)|null $transport
     *                                     injection seam — hermetic tests never open a socket
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model = '',
        private readonly ?string $token = null,
        private readonly bool $allowPrivate = false,
               private readonly int $timeoutSec = self::DEFAULT_TIMEOUT_SEC,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    /**
     * The token, read exactly once at use time by the command that arms
     * the client. Empty env = unset (a blank bearer is never sent).
     */
    public static function tokenFromEnv(): ?string
    {
        $raw = getenv(self::TOKEN_ENV);
        if (!is_string($raw)) {
            return null;
        }
        $trim = trim($raw);

        return $trim === '' ? null : $trim;
    }

    /**
     * Shape-only admission for the llm_url key family (zero resolver
     * calls), reusing the ml-pair guard's full address matrix and merely
     * relabelling the key names in every message — so an operator reading
     * a refusal sees llm_url/llm_allow_private, never a foreign key.
     * The guard's CLI hint is dropped (llm_* are config-file keys; no
     * argv spelling exists).
     */
    public static function urlAdmissionError(string $baseUrl, bool $allowPrivate): ?string
    {
        $refusal = MlPairClient::pairUrlAdmissionError(
            self::endpointUrl($baseUrl, '/annotate'),
            $allowPrivate,
        );
        if ($refusal === null) {
            return null;
        }

        return str_replace(
            [' (CLI: --ml-pair-allow-private)', 'ml_pair_allow_private', 'ml_pair_url'],
            ['', 'llm_allow_private', 'llm_url'],
            $refusal,
        );
    }

    public static function endpointUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . $path;
    }

    /**
     * Host portion of the configured base, for cache scoping and for the
     * audit doc's provenance block (never a token, never a URL query).
     */
    public function host(): string
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : '';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function hasToken(): bool
    {
        return $this->token !== null;
    }

    /**
     * @param list<array<string, mixed>> $packs
     * @return list<array<string, mixed>>|null normalized annotation rows, or null on any failure
     */
    public function annotateBatch(array $packs): ?array
    {
        $payload = [
            'model'        => $this->model,
            'temperature'  => 0,
            'instructions' => LlmPrompts::annotatePrompt($packs),
            'packs'        => array_map(
                static fn (array $pack): array => [
                    'finding_id' => $pack['finding_id'] ?? null,
                    'pack'       => $pack,
                ],
                $packs,
            ),
        ];
        $decoded = $this->postJson('/annotate', $payload);
        if ($decoded === null || !array_key_exists('annotations', $decoded) || !is_array($decoded['annotations'])) {
            return null;
        }

        $rows = [];
        foreach ($decoded['annotations'] as $row) {
            $normalized = self::normalizeAnnotation($row);
            if ($normalized === null) {
                continue; // per-row shape guard: garbage rows lose only themselves
            }
            $rows[$normalized['finding_id']] ??= $normalized;
        }

        return $rows === [] ? null : array_values($rows);
    }

    /**
     * @param list<array<string, mixed>> $annotated
     * @return array{themes: list<array<string, mixed>>, summary: string, landing_order: list<string>}|null
     */
    public function mergeThemes(array $annotated): ?array
    {
        $payload = [
            'model'        => $this->model,
            'temperature'  => 0,
            'instructions' => LlmPrompts::themesPrompt($annotated),
            'findings'     => $annotated,
        ];
        $decoded = $this->postJson('/themes', $payload);
        if ($decoded === null) {
            return null;
        }
        if (!is_array($decoded['themes'] ?? null) || !is_string($decoded['summary'] ?? null)
            || !is_array($decoded['landing_order'] ?? null)) {
            return null;
        }

        $themes = [];
        foreach ($decoded['themes'] as $theme) {
            if (!is_array($theme) || !is_string($theme['name'] ?? null) || $theme['name'] === ''
                || !is_array($theme['members'] ?? null)) {
                return null;
            }
            $members = [];
            foreach ($theme['members'] as $member) {
                if (!is_string($member) || $member === '') {
                    return null;
                }
                $members[] = $member;
            }
            $themes[] = [
                'name'    => $theme['name'],
                'label'   => is_string($theme['label'] ?? null) ? $theme['label'] : '',
                'members' => $members,
            ];
        }
        $landing = [];
        foreach ($decoded['landing_order'] as $step) {
            if (!is_string($step) || $step === '') {
                return null;
            }
            $landing[] = $step;
        }

        return ['themes' => $themes, 'summary' => $decoded['summary'], 'landing_order' => $landing];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed>|null
     */
    private function postJson(string $path, array $payload): ?array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return null;
        }
        $raw = $this->transport === null
            ? $this->post($path, $body)
            : ($this->transport)(self::endpointUrl($this->baseUrl, $path), $body, $this->headerLines());
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private function headerLines(): array
    {
        $headers = [];
        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        return $headers;
    }

    private function post(string $path, string $body): ?string
    {
        $url      = self::endpointUrl($this->baseUrl, $path);
        $decision = $this->decision();
        if ($decision === null) {
            return null;
        }
        $response = Http::curlPost(
            $url,
            $body,
            MlClient::curlPinEntries($decision),
            null,
            $this->timeoutSec,
            self::MAX_RESPONSE_BYTES,
            $this->headerLines(),
        );
        if ($response !== null) {
            return $response;
        }

        return Http::streamPost(
            MlClient::pinnedStreamRequest($url, $decision),
            $body,
            $this->timeoutSec,
            self::MAX_RESPONSE_BYTES,
            $this->headerLines(),
        );
    }

    /**
     * @return array{host: string, port: int, addresses: list<string>}|null
     */
    private function decision(): ?array
    {
        if ($this->decisionResolved) {
            return $this->decision;
        }
        $this->decisionResolved = true;
        // The Config door already admitted llm_url's SHAPE under the
        // llm_* spelling (LlmClient::urlAdmissionError). This call is the
        // dial-time DNS + address-matrix decision feeding the pinning;
        // its internal re-admission speaks the ml_* key names because it
        // is the shared MlPairClient guard reached with default params.
        $this->decision = MlPairClient::pairEndpointDecision(
            self::endpointUrl($this->baseUrl, '/annotate'),
            $this->allowPrivate,
        );

        return $this->decision;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeAnnotation(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $findingId = $row['finding_id'] ?? null;
        if (!is_string($findingId) || $findingId === '') {
            return null;
        }
        foreach (['title', 'proposal', 'risk', 'effort'] as $field) {
            if (!is_string($row[$field] ?? null)) {
                return null;
            }
        }
        $risk = strtolower(trim((string) $row['risk']));
        if (!in_array($risk, LlmPrompts::RISKS, true)) {
            return null;
        }
        $effort = strtoupper(trim((string) $row['effort']));
        if (!in_array($effort, LlmPrompts::EFFORTS, true)) {
            return null;
        }
        if (!is_bool($row['intentional'] ?? null)) {
            return null;
        }

        return [
            'finding_id'  => $findingId,
            'title'       => trim((string) $row['title']),
            'proposal'    => trim((string) $row['proposal']),
            'risk'        => $risk,
            'effort'      => $effort,
            'intentional' => $row['intentional'],
        ];
    }
}
