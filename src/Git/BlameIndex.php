<?php
declare(strict_types=1);

namespace Phpdup\Git;

/**
 * Item 4.2 — the parsed answer of ONE `git blame --porcelain` run.
 *
 * Porcelain grammar (git-blame(1)): a header line
 * `<sha> <orig-line> <final-line> [<num-lines>]` starts each output line;
 * the FIRST occurrence of a commit carries its metadata block (author,
 * author-time, committer-time, summary, optional `previous <sha> <path>`
 * for the boundary this commit crossed) before the tab-prefixed content
 * line; later occurrences are header + content only. That is exactly the
 * doc's "parsed once into line->(sha,author,time,msg)".
 *
 * Two honest wrinkles the analyzer downstream must respect:
 *  - lines whose commit is all-zero sha are NOT COMMITTED YET (working
 *    tree edits); they map to null and contribute no date;
 *  - `previous <sha> <path>` headers name the parent path of a boundary
 *    commit, and a `filename <path>` header names the path the content
 *    lived under at its blamed commit — together the rename aliases the
 *    co-change leg uses to keep credit for history under an old name.
 *
 * A malformed stream yields null from parse() — never a half-trust object;
 * the caller then reports 'unmeasurable' rather than a plausible guess.
 */
final class BlameIndex
{
    private const ZERO_SHA = '0000000000000000000000000000000000000000';

    /**
     * @param array<int, string|null> $lineSha final line number -> commit sha (null = uncommitted)
     * @param array<string, array{author: string, time: int, msg: string}> $commits sha -> metadata
     * @param list<string> $previousPaths path aliases seen in the stream (previous boundary headers, and filename paths differing from the blamed name)
     */
    private function __construct(
        public readonly array $lineSha,
        public readonly array $commits,
        public readonly array $previousPaths,
    ) {
    }

    /**
     * Rebuild an index from cached parts (HistoryCache hit path).
     *
     * @param array<int, string|null> $lineSha
     * @param array<string, array{author: string, time: int, msg: string}> $commits
     * @param list<string> $previousPaths
     */
    public static function fromParts(array $lineSha, array $commits, array $previousPaths): self
    {
        ksort($lineSha);
        sort($previousPaths);
        return new self($lineSha, $commits, $previousPaths);
    }

    /**
     * @return self|null null when the stream is corrupt or not blame output
     */
    public static function parse(string $porcelain): ?self
    {
        /** @var array<int, string|null> $lineSha */
        $lineSha = [];
        /** @var array<string, array{author: string, time: int, msg: string}> $commits */
        $commits = [];
        /** @var array<string, true> $prevPaths parent paths seen at rename boundaries */
        $prevPaths = [];

        $curSha         = null; // sha of the header we are between
        $curLine        = null; // final-line number of that header
        $pendingAuthor  = null;
        $pendingTime    = null;
        $pendingSummary = null;
        $seenAnything   = false;

        foreach (explode("\n", $porcelain) as $raw) {
            if ($raw === '') {
                continue;
            }
            // Content line: terminates the current line's block.
            if ($raw[0] === "\t") {
                if ($curSha === null || $curLine === null) {
                    return null; // content without a header — not porcelain
                }
                if ($curSha === self::ZERO_SHA) {
                    $lineSha[$curLine] = null;
                } else {
                    $lineSha[$curLine] = $curSha;
                    if (!isset($commits[$curSha])) {
                        if ($pendingAuthor === null || $pendingTime === null || $pendingSummary === null) {
                            return null; // first occurrence must carry metadata
                        }
                        $commits[$curSha] = [
                            'author' => $pendingAuthor,
                            'time'   => $pendingTime,
                            'msg'    => $pendingSummary,
                        ];
                    }
                }
                $curSha         = null;
                $curLine        = null;
                $pendingAuthor  = null;
                $pendingTime    = null;
                $pendingSummary = null;
                $seenAnything   = true;
                continue;
            }
            // Header line: "<sha> <orig> <final> [<count>]"; the count (and
            // the metadata block) rides only a commit's FIRST occurrence,
            // repeats are 3-field. Boundary commits may carry a "^" prefix.
            if (preg_match('/^\^?([0-9a-f]{40}) (\d+) (\d+)(?: (\d+))?$/', $raw, $m) === 1) {
                $curSha       = $m[1];
                $curLine      = (int)$m[3];
                $seenAnything = true;
                continue;
            }
            // Metadata lines attach to the open header.
            if ($curSha === null) {
                return null; // metadata before any header — not porcelain
            }
            if (preg_match('/^author (.+)$/', $raw, $m) === 1) {
                $pendingAuthor = $m[1];
                continue;
            }
            if (preg_match('/^author-time (\d+)$/', $raw, $m) === 1) {
                $pendingTime = (int)$m[1];
                continue;
            }
            if (preg_match('/^committer-time (\d+)$/', $raw, $m) === 1) {
                $pendingTime = (int)$m[1]; // landing time answers "last changed"
                continue;
            }
            if (preg_match('/^summary (.*)$/', $raw, $m) === 1) {
                $pendingSummary = $m[1];
                continue;
            }
            if (preg_match('/^previous [0-9a-f]{40} (.+)$/', $raw, $m) === 1) {
                $prevPaths[$m[1]] = true;
                continue;
            }
            // `filename <path>` states the path this content lived under AT
            // THE BLAMED COMMIT. When blame crossed a rename it differs from
            // the blamed file's current path — that is exactly the old name
            // the co-change leg must keep credit for (the `previous` header
            // covers boundary-style crossings; this covers plain rename
            // following, which git reports only here).
            if (preg_match('/^filename (.+)$/', $raw, $m) === 1) {
                $prevPaths[$m[1]] = true;
                continue;
            }
            if (
                preg_match('/^committer /', $raw) === 1
                || preg_match('/^author-mail /', $raw) === 1
                || preg_match('/^author-tz /', $raw) === 1
                || preg_match('/^committer-mail /', $raw) === 1
                || preg_match('/^committer-tz /', $raw) === 1
                || preg_match('/^previous boundary /', $raw) === 1
                || $raw === 'boundary'
            ) {
                continue; // inert metadata we do not consume
            }
            return null; // unknown grammar — treat the whole stream as suspect
        }

        if (!$seenAnything) {
            return null; // empty output is not a blame answer
        }
        ksort($lineSha);
        $previousPaths = array_keys($prevPaths);
        sort($previousPaths);

        return new self($lineSha, $commits, array_map('strval', $previousPaths));
    }

    /**
     * Latest committed touch of the inclusive line span, or null when no
     * committed line answers (all-uncommitted span, or a span outside the
     * blamed file — both are honest unknowns).
     *
     * @return array{sha: string, time: int, msg: string}|null
     */
    public function lastTouch(int $start, int $end): ?array
    {
        $best = null;
        foreach ($this->lineSha as $line => $sha) {
            if ($sha === null || $line < $start || $line > $end) {
                continue;
            }
            $commit = $this->commits[$sha] ?? null;
            if ($commit === null) {
                return null; // dangling reference: refuse to half-answer
            }
            if ($best === null || $commit['time'] > $best['time']) {
                $best = ['sha' => $sha, 'time' => $commit['time'], 'msg' => $commit['msg']];
            }
        }
        return $best;
    }

    /**
     * Every committed sha whose lines fall in the inclusive span.
     *
     * @return list<string>
     */
    public function commitsInRange(int $start, int $end): array
    {
        $set = [];
        foreach ($this->lineSha as $line => $sha) {
            if ($sha === null || $line < $start || $line > $end) {
                continue;
            }
            if (!isset($this->commits[$sha])) {
                return []; // corrupt — callers treat empty as no answer
            }
            $set[$sha] = true;
        }
        $list = array_keys($set);
        sort($list);
        return array_map('strval', $list);
    }

    /**
     * Commit metadata, or null for an unknown sha.
     *
     * @return array{author: string, time: int, msg: string}|null
     */
    public function commit(string $sha): ?array
    {
        return $this->commits[$sha] ?? null;
    }
}
