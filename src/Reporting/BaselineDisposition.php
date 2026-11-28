<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * One recorded disposition decision for a stable finding id (item 4.4).
 *
 * The registry file (--baseline=<path>) is the durable half of the
 * accepted-duplication workflow: besides the member-hash gate entries,
 * it may carry a top-level "dispositions" map of finding_id -> record
 * saying what the team DECIDED about that finding. Two statuses exist:
 *
 *  - accepted:   a deliberate "keep this duplication, acknowledged"
 *                decision. The finding drops out of the main report
 *                and is enumerated in the baseline appendix instead.
 *  - unresolved: a seen-but-not-decided finding. It never suppresses
 *                anything; it exists so a later pass can triage it.
 *
 * Provenance is recorded at accept time exactly as the doc demands
 * ("with provenance and timestamp"): the canonical member file list,
 * their mtimes, the git HEAD sha when the analysis ran inside a repo,
 * the member content-hashes, and an ISO-8601 accepted_at stamp.
 *
 * Default posture mirrors FunctionNameRegistry (docs/CLI.md): decisions
 * are NEVER persisted unless the operator explicitly writes a baseline
 * file. This object is the parsed form of one file record only.
 */
final class BaselineDisposition
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_UNRESOLVED = 'unresolved';

    /**
     * @param list<string> $files canonical member file paths at accept time
     * @param array<string, int> $mtimes canonical path => filemtime at accept time
     * @param list<string> $memberHashes
     */
    public function __construct(
        public readonly string $findingId,
        public readonly string $status,
        public readonly ?string $note = null,
        public readonly ?string $acceptedAt = null,
        public readonly ?string $gitSha = null,
        public readonly array $files = [],
        public readonly array $mtimes = [],
        public readonly array $memberHashes = [],
    ) {
        if ($findingId === '') {
            throw new \InvalidArgumentException('BaselineDisposition requires a non-empty findingId');
        }

        if (!in_array($status, [self::STATUS_ACCEPTED, self::STATUS_UNRESOLVED], true)) {
            throw new \InvalidArgumentException(
                "BaselineDisposition status must be 'accepted' or 'unresolved', got: $status",
            );
        }
    }

    /**
     * Rehydrate a disposition from an appendix row produced during this
     * run (BaselineFilter row shape). Used by the baseline write legs to
     * persist directive/config acceptances — the rows already carry the
     * full provenance snapshot captured at accept time.
     *
     * @param array<string, mixed> $row
     */
    public static function fromAppendixRow(array $row): self
    {
        $findingId = (string)($row['finding_id'] ?? '');

        return new self(
            findingId: $findingId,
            status: (string)($row['status'] ?? self::STATUS_ACCEPTED),
            note: isset($row['note']) && is_string($row['note']) ? $row['note'] : null,
            acceptedAt: isset($row['accepted_at']) && is_string($row['accepted_at'])
                ? $row['accepted_at']
                : date('c'),
            gitSha: isset($row['git_sha']) && is_string($row['git_sha']) ? $row['git_sha'] : null,
            files: array_values(array_map('strval', (array)($row['files'] ?? []))),
            mtimes: array_map('intval', (array)($row['mtimes'] ?? [])),
            memberHashes: array_values(array_map('strval', (array)($row['member_hashes'] ?? []))),
        );
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $record = ['status' => $this->status];

        if ($this->note !== null) {
            $record['note'] = $this->note;
        }

        if ($this->acceptedAt !== null) {
            $record['accepted_at'] = $this->acceptedAt;
        }

        $provenance = [];
        if ($this->files !== []) {
            $provenance['files'] = $this->files;
        }
        if ($this->mtimes !== []) {
            $provenance['mtimes'] = $this->mtimes;
        }
        if ($this->gitSha !== null) {
            $provenance['git_sha'] = $this->gitSha;
        }
        if ($this->memberHashes !== []) {
            $provenance['member_hashes'] = $this->memberHashes;
        }
        if ($provenance !== []) {
            $record['provenance'] = $provenance;
        }

        return $record;
    }

    /**
     * Parse one dispositions-map record. The key shape is validated;
     * unknown extra fields inside provenance are preserved by ignoring
     * them (forward-compatible payload growth).
     *
     * @param array<array-key, mixed> $record
     * @throws \RuntimeException on any malformed field (loud, names $path)
     */
    public static function fromArray(string $findingId, array $record, string $path): self
    {
        if (!isset($record['status']) || !is_string($record['status'])) {
            throw new \RuntimeException(
                "Baseline disposition '$findingId' must carry a string 'status': $path",
            );
        }

        if (!in_array($record['status'], [self::STATUS_ACCEPTED, self::STATUS_UNRESOLVED], true)) {
            throw new \RuntimeException(
                "Baseline disposition '$findingId' has unknown status '{$record['status']}' "
                . "(expected 'accepted' or 'unresolved'): $path",
            );
        }

        if (isset($record['note']) && !is_string($record['note'])) {
            throw new \RuntimeException("Baseline disposition '$findingId' 'note' must be a string: $path");
        }

        if (isset($record['accepted_at']) && !is_string($record['accepted_at'])) {
            throw new \RuntimeException("Baseline disposition '$findingId' 'accepted_at' must be a string: $path");
        }

        $files = [];
        $mtimes = [];
        $memberHashes = [];
        $gitSha = null;

        if (isset($record['provenance'])) {
            if (!is_array($record['provenance'])) {
                throw new \RuntimeException("Baseline disposition '$findingId' 'provenance' must be an object: $path");
            }
            $prov = $record['provenance'];

            if (isset($prov['files'])) {
                if (!is_array($prov['files'])) {
                    throw new \RuntimeException("Baseline disposition '$findingId' provenance 'files' must be an array: $path");
                }
                foreach ($prov['files'] as $file) {
                    if (!is_string($file) || $file === '') {
                        throw new \RuntimeException("Baseline disposition '$findingId' provenance 'files' must contain non-empty strings: $path");
                    }
                    $files[] = $file;
                }
            }

            if (isset($prov['mtimes'])) {
                if (!is_array($prov['mtimes'])) {
                    throw new \RuntimeException("Baseline disposition '$findingId' provenance 'mtimes' must be an object: $path");
                }
                foreach ($prov['mtimes'] as $file => $mtime) {
                    if (!is_string($file) || $file === '' || !is_int($mtime) || $mtime < 0) {
                        throw new \RuntimeException("Baseline disposition '$findingId' provenance 'mtimes' must map paths to non-negative integers: $path");
                    }
                    $mtimes[$file] = $mtime;
                }
            }

            if (isset($prov['git_sha'])) {
                if (!is_string($prov['git_sha']) || $prov['git_sha'] === '') {
                    throw new \RuntimeException("Baseline disposition '$findingId' provenance 'git_sha' must be a non-empty string: $path");
                }
                $gitSha = $prov['git_sha'];
            }

            if (isset($prov['member_hashes'])) {
                if (!is_array($prov['member_hashes'])) {
                    throw new \RuntimeException("Baseline disposition '$findingId' provenance 'member_hashes' must be an array: $path");
                }
                foreach ($prov['member_hashes'] as $hash) {
                    if (!is_string($hash) || $hash === '') {
                        throw new \RuntimeException("Baseline disposition '$findingId' provenance 'member_hashes' must contain non-empty strings: $path");
                    }
                    $memberHashes[] = $hash;
                }
            }
        }

        return new self(
            findingId: $findingId,
            status: $record['status'],
            note: isset($record['note']) ? (string)$record['note'] : null,
            acceptedAt: isset($record['accepted_at']) ? (string)$record['accepted_at'] : null,
            gitSha: $gitSha,
            files: $files,
            mtimes: $mtimes,
            memberHashes: $memberHashes,
        );
    }
}
