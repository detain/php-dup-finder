<?php
/**
 * bench/audit-truth/extract-phlix.php — ground-truth adapter #2 (improve_logic.md §6.2).
 *
 * Parses /home/sites/phlix/refactor.md (149-finding scan report; the mechanical
 * pass recovers the ~138 dossier-head findings — the 11-head gap lives in the
 * §1–§6 summary tables and is an LLM-pass job; see bench/audit-truth/LLM-PASS.md)
 * into derived-truth records. Output is written OUTSIDE the repo (privacy gate —
 * see bench/audit-truth/README.md).
 *
 * Report anatomy (differs from /home/my — two adapters are REQUIRED, f15 §2):
 *   Dossier finding heads:   ## A-01 — title   /   ### A-02 — title
 *   NO LOCATIONS bullets. Locations are INLINE prose citations in three dialects:
 *     D1 file anchor   `S:src/Http/Response.php` ↔ `H:src/Http/Response.php`
 *     D2 side-tagged   `S:212-215`  `H:166`      `srv :151/:473`
 *     D3 bare          `:461-472`   `File.php:127`
 *     D4 full path     `src/Media/ItemRepository.php:40-58` (no side tag)
 *   Currency stamps: `[W6 …]` brackets mark findings cured at post-scan commits.
 *   STRIPPED before parsing — ground truth is the SCAN-ERA claim; a CURED finding
 *   still counts as a benchmark hit because the corpus is pinned at scan commits
 *   (375f7992 server / ba1ae3b8 hub — bench/corpus-pins.txt).
 *
 * Resolution law: D2 attaches to that side's current anchor; D3 attaches to the
 * most-recently-touched anchor; D4 (and anchorless D3) resolve against the pinned
 * manifests — a path/basename present in exactly one side's file list is a
 * mechanical deterministic bind; unresolved tokens are kept with origin
 * needs_context for the LLM pass (never guessed away silently).
 *
 * Usage:
 *   php bench/audit-truth/extract-phlix.php [--report /home/sites/phlix/refactor.md]
 *       [--out /tmp/phlix.truth.json]
 *       [--manifest-server <filelist>] [--manifest-hub <filelist>] [--stats]
 */
declare(strict_types=1);

const PHLIX_SIDE_REPOS = ['S' => 'phlix-server', 'H' => 'phlix-hub'];

/** Title-label class map (doc §2.1 letters; see schema.md). */
const PHLIX_CLASS_RULES = [
    ['G', ['under-adopt', 'ssot', 'single-source', 'adopts its own']],
    ['E', ['table', 'map', 'grammar', 'constant', 'literal', 'config']],
    ['F', ['mirror', '↔ twin', 'twin pair']],
    ['A', ['exact clone', 'byte-identical', 'near-exact', 'near-duplicate', 'clone', 'identical', 'duplicate', 'twin', 'folded', 'copies']],
    ['B', ['idiom', 'helper', 'boilerplate', 'hand-rolled', 'spine']],
    ['D', ['divergent', 'same-intent', 'drift', 'fork', 'two designs', 'reimplement']],
    ['C', ['scaffold', 'template', 'generated']],
];

$opts = getopt('', ['report::', 'out::', 'manifest-server::', 'manifest-hub::', 'stats']);
$reportPath  = (string)($opts['report'] ?? '/home/sites/phlix/refactor.md');
$outPath     = (string)($opts['out'] ?? '/tmp/phlix.truth.json');
$manifests   = [
    'S' => phlixLoadManifest($opts['manifest-server'] ?? null),
    'H' => phlixLoadManifest($opts['manifest-hub'] ?? null),
];

if (!is_file($reportPath)) {
    fwrite(STDERR, "[err] report not readable: {$reportPath}\n");
    exit(2);
}
$text = (string)file_get_contents($reportPath);

$findings = phlixParseReport($text, $manifests);

$payload = [
    'corpus'        => 'phlix',
    'source_report' => $reportPath,
    'source_sha256' => (string)hash_file('sha256', $reportPath),
    'derived_by'    => 'bench/audit-truth/extract-phlix.php',
    'schema'        => 'audit-truth/1',
    'scan_pins'     => ['server' => '375f7992', 'hub' => 'ba1ae3b8'],
    'heads_parsed'  => count($findings),
    'doc_claimed_findings' => 149,
    'findings'      => $findings,
];
if (file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    fwrite(STDERR, "[err] cannot write {$outPath} (truth JSON must stay OUTSIDE the repo — privacy gate)\n");
    exit(2);
}
echo "[ok] wrote {$outPath}\n";
if (isset($opts['stats'])) {
    phlixPrintStats($findings);
}

/** @param array{S:?array<string,true>,H:?array<string,true>} $manifests @return list<array<string,mixed>> */
function phlixParseReport(string $text, array $manifests): array
{
    $heads = [];
    // Two head shapes coexist in the corpus (verified against pinned bytes):
    //   "### A-01 — title"  (em-dash separated)   and   "## B-01 title" (bare).
    preg_match_all('/^#{2,3} ([A-H]-\d+)(?: — | )(.+)$/m', $text, $hs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    // Lane dossier counts (`## Counts: findings=24 exact=8 near=9 intent=7`).
    $laneCounts = [];
    if (preg_match_all('/^## Counts: (.*findings=\d+.*)$/m', $text, $cs, PREG_SET_ORDER)) {
        //associate each Counts: line with the NEXT lane dossier heading's letter
        // (Counts lines trail the dossier they describe in current corpus shape).
        $cur = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^## Dossier ([A-H])/', $line, $m)) { $cur = $m[1]; }
            if (preg_match('/^## Counts: (.*)$/', $line, $m) && $cur !== null) {
                $laneCounts[$cur] = $m[1];
            }
        }
    }

    foreach ($hs as $i => $h) {
        $startByte = (int)$h[0][1] + strlen($h[0][0]);
        $endByte   = isset($hs[$i + 1]) ? (int)$hs[$i + 1][1] : strlen($text);
        // Cut at any intervening heading (## section tails inside a dossier body).
        if (preg_match('/^#{1,4} /m', substr($text, $startByte, $endByte - $startByte), $mm, PREG_OFFSET_CAPTURE)) {
            $endByte = $startByte + (int)$mm[0][1];
        }
        $raw  = substr($text, $startByte, $endByte - $startByte);
        $w6   = 0;
        $body = preg_replace_callback('/\[W6[^\]]*\]/s', static function () use (&$w6): string { $w6++; return ' '; }, $raw) ?? $raw;

        [$members, $unresolved] = phlixExtractMembers($body, $manifests);

        $lane  = $h[1][0][0];
        $title = trim($h[2][0]);
        // W6 currency stamps ride INSIDE heading text too (e.g. A-06 embeds a
        // [W6-CLOSEOUT … CURED …] bracket) — strip under the same law as bodies:
        // ground truth is the scan-era claim, stamps are post-scan bookkeeping.
        $title = trim((string)preg_replace_callback('/\[W6[^\]]*\]/s', static function () use (&$w6): string { $w6++; return ' '; }, $title));
        $cls   = '??';
        $low   = mb_strtolower($title);
        foreach (PHLIX_CLASS_RULES as [$c, $needles]) {
            foreach ($needles as $needle) {
                if (str_contains($low, $needle)) { $cls = $c; break 2; }
            }
        }
        $drift = (bool)preg_match('/Drift risk: *(HIGH|MED|H\b)/iu', $body)
              || (bool)preg_match('/diverg|drift|only in|missing (the )?guard|one-sided/iu', $body);

        $findings[] = [
            'id'          => $h[1][0],
            'lane'        => $lane,
            'class'       => $cls,
            'title'       => $title,
            'members'     => $members,
            'drift'       => $drift,
            'w6_stamps_stripped' => $w6,
            'lane_claim_counts'  => $laneCounts[$lane] ?? null,
            'needs_llm_pass' => [
                'unresolved_tokens' => $unresolved,
                'zero_members'      => $members === [],
            ],
        ];
    }
    return $findings;
}

/**
 * Ordered scan over the (W6-stripped) body. Anchor state machine + citation
 * dialects D1–D3; returns members + list of unresolved raw tokens.
 *
 * @param array{S:?array<string,true>,H:?array<string,true>} $manifests
 * @return array{0:list<array<string,mixed>>,1:list<string>}
 */
function phlixExtractMembers(string $body, array $manifests): array
{
    // Backticks are OPTIONAL on the file-bearing dialects (D4/D3a): the corpus cites
    // `File.php:NN` bare 401x vs 101x backticked. The backtick stays MANDATORY on the
    // contextless bare `:NN` (D3b) so prose digits/ports never enter the truth stream.
    $pattern = '/`(?<side>[SH]):(?<path>(?:src|include|public_html)\/[A-Za-z0-9_\/.\-]+\.php)(?::(?<pa>\d+)(?:-(?<pb>\d+))?)?`'
             . '|`(?<side2>[SH]):(?<sa>\d+)(?:-(?<sb>\d+))?`'
             . '|(?<word>\b(?:srv|hub)\s+(?<wrange>(?::\d+(?:-\d+)?)(?:\/(?::?\d+(?:-\d+)?))*))'
             . '|(?<fullpath>(?:src|include|public_html)\/[A-Za-z0-9_\/.\-]+\.php)(?::(?<ga>\d+)(?:-(?<gb>\d+))?)?'
             . '|(?<file>[A-Za-z0-9_\-]+\.(?:inc\.)?php)(?::(?<fa>\d+)(?:-(?<fb>\d+))?)?'
             . '|`:(?<ba>\d+)(?:-(?<bb>\d+))?`/u';
    preg_match_all($pattern, $body, $ms, PREG_SET_ORDER);

    $anchors = ['S' => null, 'H' => null]; // side => repo-relative file
    $last    = null;                       // most-recently touched side
    $members = [];
    $unresolved = [];

    foreach ($ms as $m) {
        if (!empty($m['side']) && !empty($m['path'])) {            // D1 anchor
            $anchors[$m['side']] = $m['path'];
            $last = $m['side'];
            if (!empty($m['pa'])) {
                $members[] = phlixMember($m['side'], $m['path'], (int)$m['pa'], isset($m['pb']) && $m['pb'] !== '' ? (int)$m['pb'] : (int)$m['pa'], 'anchor-inline', $manifests);
            }
        } elseif (!empty($m['side2']) && !empty($m['sa'])) {        // D2 side-tagged
            $side = $m['side2'];
            $file = $anchors[$side];
            if ($file === null) { $unresolved[] = $m[0]; continue; }
            $members[] = phlixMember($side, $file, (int)$m['sa'], isset($m['sb']) && $m['sb'] !== '' ? (int)$m['sb'] : (int)$m['sa'], 'side-tagged', $manifests);
            $last = $side;
        } elseif (!empty($m['word'])) {                              // D2b srv/hub word-tagged, e.g. `srv :151/:473`
            $side = (bool)preg_match('/\bsrv\b/i', $m['word']) ? 'S' : 'H';
            $ranges = [];
            if (preg_match_all('/:(\d+)(?:-(\d+))?/', $m['wrange'], $rs, PREG_SET_ORDER)) {
                foreach ($rs as $r) { $ranges[] = [(int)$r[1], isset($r[2]) && $r[2] !== '' ? (int)$r[2] : (int)$r[1]]; }
            }
            $file = $anchors[$side];
            if ($file === null || $ranges === []) { $unresolved[] = $m[0]; continue; }
            foreach ($ranges as [$s, $e]) {
                $members[] = phlixMember($side, $file, $s, $e, 'side-tagged', $manifests);
            }
            $last = $side;
        } elseif (!empty($m['fullpath'])) {                          // D4 repo-relative full path, no side tag
            $file = (string)$m['fullpath'];
            $side = null; $origin = 'path-manifest';
            $inS = $manifests['S'] !== null && isset($manifests['S'][$file]);
            $inH = $manifests['H'] !== null && isset($manifests['H'][$file]);
            if ($inS && !$inH) { $side = 'S'; }
            elseif ($inH && !$inS) { $side = 'H'; }
            elseif ($inS && $inH && $last !== null && $anchors[$last] !== null) {
                $side = $last; $origin = 'path-last-side';          // twin path: prose context decides
            } else {
                [$side, $file] = phlixUniqueBasename(basename($m['fullpath']), $manifests); // renamed/shortened cite
                if ($side === null) { $unresolved[] = $m[0]; continue; }
                $origin = 'path-basename-manifest';
            }
            $start = !empty($m['ga']) ? (int)$m['ga'] : null;
            $end   = $start === null ? null : (isset($m['gb']) && $m['gb'] !== '' ? (int)$m['gb'] : $start);
            $anchors[$side] = $file; $last = $side;                 // chains following bare cites
            $members[] = phlixMember($side, $file, $start, $end, $origin, $manifests);
        } elseif (!empty($m['file'])) {                              // D3a basename(:range)
            $side = null; $file = null; $origin = 'basename';
            foreach (['S', 'H'] as $cand) {
                if ($anchors[$cand] !== null && str_ends_with($anchors[$cand], '/' . $m['file'])) { $side = $cand; $file = $anchors[$cand]; break; }
            }
            if ($side === null) {
                // Same-repo lanes cite `File.php:NN` with no S:/H: anchor ever set in
                // the body: a basename present exactly once across both pinned manifests
                // is a mechanical, deterministic bind — take it and set that side's anchor
                // so following bare `:NN` cites chain. Ambiguous stays unresolved.
                [$side, $file] = phlixUniqueBasename($m['file'], $manifests);
                if ($side !== null) {
                    $origin = 'basename-manifest';
                    $anchors[$side] = (string)$file;
                    $last = $side;
                }
            }
            if ($side === null) { $unresolved[] = $m[0]; continue; }
            $start = !empty($m['fa']) ? (int)$m['fa'] : null;
            $end   = $start === null ? null : (isset($m['fb']) && $m['fb'] !== '' ? (int)$m['fb'] : $start);
            $members[] = phlixMember((string)$side, (string)$file, $start, $end, $origin, $manifests);
        } elseif (!empty($m['ba'])) {                                // D3b bare :NN(-MM)
            if ($last === null || $anchors[$last] === null) { $unresolved[] = $m[0]; continue; }
            $start = (int)$m['ba'];
            $end   = isset($m['bb']) && $m['bb'] !== '' ? (int)$m['bb'] : $start;
            $members[] = phlixMember($last, (string)$anchors[$last], $start, $end, 'bare-last-anchor', $manifests);
        }
    }
    return [phlixDedupe($members), $unresolved];
}

/** @param array{S:?array<string,true>,H:?array<string,true>} $manifests @return array<string,mixed> */
function phlixMember(string $side, string $file, ?int $start, ?int $end, string $origin, array $manifests): array
{
    $verified = null;
    if ($manifests[$side] !== null) {
        $verified = isset($manifests[$side][$file]);
    }
    return [
        'repo'     => PHLIX_SIDE_REPOS[$side],
        'file'     => $file,
        'start'    => $start,
        'end'      => $end,
        'origin'   => $origin,
        'verified' => $verified,
    ];
}

/** @param list<array<string,mixed>> $members @return list<array<string,mixed>> */
function phlixDedupe(array $members): array
{
    $seen = []; $out = [];
    foreach ($members as $m) {
        $key = $m['repo'] . '|' . $m['file'] . '|' . ($m['start'] ?? '-') . '|' . ($m['end'] ?? '-');
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $out[] = $m;
    }
    return $out;
}

/** @return array<string,true>|null */
function phlixLoadManifest(?string $path): ?array
{
    if ($path === null || !is_file($path)) { return null; }
    return array_flip(array_values(array_filter(explode("\n", (string)file_get_contents($path)))));
}

/**
 * A basename with exactly ONE full-path hit across both pinned manifests is a
 * mechanical, deterministic bind. Ambiguous (multi-hit / both-sides / no manifest)
 * returns [null, null] — prose context is the LLM pass's job, never a silent guess.
 *
 * @param array{S:?array<string,true>,H:?array<string,true>} $manifests
 * @return array{0:?string,1:?string}
 */
function phlixUniqueBasename(string $basename, array $manifests): array
{
    $hits = [];
    foreach (['S', 'H'] as $cand) {
        if ($manifests[$cand] === null) { continue; }
        foreach ($manifests[$cand] as $rel => $_) {
            if (str_ends_with((string)$rel, '/' . $basename)) { $hits[] = [$cand, (string)$rel]; }
        }
    }
    return count($hits) === 1 ? [$hits[0][0], $hits[0][1]] : [null, null];
}

/** @param list<array<string,mixed>> $findings */
function phlixPrintStats(array $findings): void
{
    $classes = []; $members = 0; $noRange = 0; $withMembers = 0; $drift = 0; $zero = 0; $unres = 0; $w6 = 0; $byLane = [];
    foreach ($findings as $f) {
        $classes[$f['class']] = ($classes[$f['class']] ?? 0) + 1;
        $byLane[substr($f['id'], 0, 1)] = ($byLane[substr($f['id'], 0, 1)] ?? 0) + 1;
        $drift += $f['drift'] ? 1 : 0;
        $w6    += $f['w6_stamps_stripped'];
        if ($f['members'] !== []) { $withMembers++; } else { $zero++; }
        $unres += count($f['needs_llm_pass']['unresolved_tokens']);
        foreach ($f['members'] as $mm) {
            $members++;
            if ($mm['start'] === null) { $noRange++; }
        }
    }
    ksort($classes); ksort($byLane);
    printf("heads parsed: %d (doc claims 149; delta %d = summary-table job for the LLM pass)\n", count($findings), 149 - count($findings));
    echo "by lane: " . json_encode($byLane) . "\n";
    echo "class split: " . json_encode($classes) . "\n";
    printf("findings with >=1 member: %d (zero-member: %d)\n", $withMembers, $zero);
    printf("member tokens: %d (rangeless: %d)\n", $members, $noRange);
    printf("unresolved citation tokens (LLM-pass tail): %d\n", $unres);
    printf("W6 currency stamps stripped: %d\n", $w6);
    printf("drift findings: %d\n", $drift);
}
