<?php

declare(strict_types=1);

namespace Phpdup\Ml;

/**
 * Item 8.2 — content-addressed cache for LLM verdicts, per the §8.2
 * ruling: "Cache by evidence-pack hash so reruns only pay for changed
 * findings." One file per verdict; the key folds host + model + prompt
 * version + the pack bytes, so a different endpoint, model or contract
 * never replays another's judgment.
 *
 * What is stored is judgment ONLY (title/proposal/risk/effort/intentional,
 * or the theme digest). Evidence-pack contents — and therefore any source
 * text — are NEVER written here (the ruling's "annotations ONLY, never
 * code" clause), which keeps this store safe next to the AST/cluster
 * caches it lives under. The store is an extension of the llm-* key
 * space, not the detection caches: nothing on the analyze path reads it,
 * and no detection cache key binds any llm_* knob (CacheKeyKnobReplay-
 * law: themes/corpus_rollups precedent).
 *
 * Write-mode posture (n-3, item 8.2 r1): tmp files are created at the
 * process umask and chmod'd 0600 BEFORE the rename, so the readable-
 * mode micro-window is bounded by a directory that is itself 0700 —
 * other users cannot traverse to the inode at all, and no entry ever
 * lands under its umask mode. The audit DOCUMENT, by contrast, is an
 * operator-named deliverable written at default mode exactly like
 * --json/--html (intentional asymmetry: deliverables ride the operator
 * umask; internal judgment stores do not). Neither file ever carries a
 * credential — the bearer token lives only in the live request header.
 */
final class LlmPackCache
{
    private const PREFIX = 'llm-audit';

    /**
     * @param string $dir cache root for verdicts (created 0700 on first write)
     * @param string $host endpoint host — judgments are host-scoped on purpose
     */
    public function __construct(
        private readonly string $dir,
        private readonly string $host,
        private readonly string $model,
    ) {
    }

    /**
     * Stable key for one pack verdict. The pack document is phpdup's own
     * deterministic serialization; canonical JSON of it is the content
     * hash the letter names.
     *
     * @param array<string, mixed> $pack
     */
    public function packKey(array $pack): string
    {
        $canonical = json_encode(
            $pack,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($canonical === false) {
            // Belt: pack docs are built from plain scalars; an encode
            // failure is a programming error, not operator input — it is
            // caught at AuditCommand's per-pack degrade arm (the loop arm
            // that derives the key), where the finding is disclosed with a
            // loud note and served un-cached. The throw stays typed: no
            // caller may launder it into a silent miss.
            throw new \RuntimeException('evidence pack is not JSON-encodable: ' . json_last_error_msg());
        }

        return 'F-' . hash('sha256', self::PREFIX . '|' . LlmPrompts::PROMPT_VERSION . '|' . $this->host . '|' . $this->model . '|' . $canonical);
    }

    /**
     * Key for the theme-merge verdict, bound to the whole annotation set
     * so any changed finding invalidates the theme pass too.
     *
     * @param list<string> $packKeys
     */
    public function themesKey(array $packKeys): string
    {
        sort($packKeys);

        return 'T-' . hash('sha256', self::PREFIX . '|themes|' . LlmPrompts::PROMPT_VERSION . '|' . $this->host . '|' . $this->model . '|' . implode(',', $packKeys));
    }

    /**
     * @return array<string, mixed>|null null on miss, unreadable bytes, or
     *                                    anything that is not a JSON object
     */
    public function get(string $key): ?array
    {
        $path = $this->pathFor($key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null; // corrupt or foreign-shaped entry: treated as a miss, re-queried
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function put(string $key, array $value): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return false;
        }
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($encoded === false) {
            return false;
        }
        $path = $this->dir . '/' . $key . '.json';
        $tmp  = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $encoded . "\n") === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * Directory actually used (0700, judgment bytes) — audit reports the
     * location so operators can clear or inspect it.
     */
    public function directory(): string
    {
        return $this->dir;
    }

    private function pathFor(string $key): ?string
    {
        // Keys are hex digests by construction; anything else is a
        // programming error (or a hostile caller) and resolves to a miss.
        if (preg_match('/^[FT]-[0-9a-f]{64}$/', $key) !== 1) {
            return null;
        }

        return $this->dir . '/' . $key . '.json';
    }
}
