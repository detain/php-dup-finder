<?php
/**
 * bench/audit-truth/extract-my.php — ground-truth adapter #1 (improve_logic.md §6.2).
 *
 * Parses /home/my/refactor.md (the 187-entry agent report) into derived-truth
 * records {id, class, members:[{file,start,end}], drift} WITHOUT copying any
 * report prose into the repo. Output is written OUTSIDE the repository by
 * default (privacy gate — see bench/audit-truth/README.md).
 *
 * Report anatomy (verified against the pinned bytes in bench/corpus-pins.txt):
 *   # S<n> — section header        → provides the id namespace. Entry numbering
 *                                    restarts per section, so ids are
 *                                    {SECTION}-{N} (fixes the f15 prototype's
 *                                    S1-collision — do NOT ship raw "N" ids).
 *   ### <n>. Title — type — size — risk LEVEL   → entry heading
 *   - LOCATIONS: ...   - EVIDENCE: ...   - PROPOSAL: ...   - RISK/DIVERGENCE: ...
 *
 * Mechanical scope (f15 §2 stage 1): explicit `file:start-end` tokens with
 * brace expansion ({Start,Stop}.php), multi-ranges (:33-50+69-86,
 * :25-42,71-88), directory-prefix continuation for relative cites, and the
 * ⚠-marker drift seed. Prose-counted families ("same 25-file verb set",
 * "+N more") are DELIBERATELY unresolved and flagged `needs_llm_pass`.
 *
 * Usage (getopt requires the = form):
 *   php bench/audit-truth/extract-my.php --report=/home/my/refactor.md \
 *       --out=/tmp/my.truth.json [--manifest=<repo-relative filelist>] [--stats]
 *
 * --manifest: one corpus-relative path per line, e.g.
 *   git -C /home/my ls-tree -r --name-only <scan-pin> | sort > manifest-my.txt
 * When present each member carries verified:true/false (direct match, else
 * unique-suffix re-anchor); when absent, verified:null.
 */
declare(strict_types=1);

const MY_ABS_PREFIX = '/home/my/';

/** §2.1 class map, widened from the f15 prototype (which mapped 116/187). Order = precedence. */
const MY_TYPE_CLASS_RULES = [
    ['E', ['literal', 'map', 'table', 'config', 'string', 'status-map']],
    ['F', ['mirror']],
    ['G', ['under-adopted', 'ssot', 'single source']],
    ['A', ['copy-paste', 'near-duplicate', 'near-identical', 'near-copy', 'duplicate',
           'duplication', 'clone', 'twin', 'fork', 'byte-identical', 'identical', 'restate']],
    ['B', ['idiom', 'boilerplate', 'pattern', 'same one-liner', 'prologue', 'preamble', 'guard']],
    ['C', ['scaffold', 'template', 'shell', 'plugin.php', 'bootstrap']],
    ['D', ['parallel', 'reimplement', 're-implementation', 'vs', 'both sides', 'divergence']],
];

$opts = getopt('', ['report::', 'out::', 'manifest::', 'stats']);
$reportPath = (string)($opts['report'] ?? '/home/my/refactor.md');
$outPath    = (string)($opts['out'] ?? '/tmp/my.truth.json');
$manifestPath = isset($opts['manifest']) ? (string)$opts['manifest'] : null;

if (!is_file($reportPath)) {
    fwrite(STDERR, "[err] report not readable: {$reportPath}\n");
    exit(2);
}
$text = (string)file_get_contents($reportPath);

$manifest = null;
if ($manifestPath !== null && is_file($manifestPath)) {
    $lines = array_values(array_filter(explode("\n", (string)file_get_contents($manifestPath))));
    $manifest = array_fill_keys($lines, true);
}

$findings = myParseReport($text, $manifest);

$payload = [
    'corpus'        => 'home-my',
    'source_report' => $reportPath,
    'source_sha256' => (string)hash_file('sha256', $reportPath),
    'derived_by'    => 'bench/audit-truth/extract-my.php',
    'schema'        => 'audit-truth/1',
    'findings'      => $findings,
];
if (file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    fwrite(STDERR, "[err] cannot write {$outPath} (truth JSON must stay OUTSIDE the repo — privacy gate)\n");
    exit(2);
}
echo "[ok] wrote {$outPath}\n";

if (isset($opts['stats'])) {
    printStats($findings);
}

/**
 * Two-pass line-driven parse: pass 1 splits raw entry records while carrying
 * the enclosing `# S<n>` section; pass 2 classifies + extracts members.
 *
 * @param array<string,true>|null $manifest @return list<array<string,mixed>>
 */
function myParseReport(string $text, ?array $manifest): array
{
    /** @var list<array{section:?string,num:string,title:string,loc:string,risk:string}> $raw */
    $raw = [];
    $section = null;
    $cur = null;
    $bucket = null; // 'loc' | 'risk' | null

    $closeCur = static function () use (&$cur, &$bucket, &$raw): void {
        if ($cur !== null) {
            $raw[] = $cur;
        }
        $cur = null;
        $bucket = null;
    };

    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (preg_match('/^# (S\d) — /u', $line, $m)) {
            $closeCur();
            $section = $m[1];
            continue;
        }
        if (preg_match('/^### (\d+)\. (.+)$/u', $line, $m)) {
            $closeCur();
            $cur = ['section' => $section, 'num' => $m[1], 'title' => trim($m[2]), 'loc' => '', 'risk' => ''];
            continue;
        }
        if (preg_match('/^#{1,4} /', $line)) {
            $closeCur();
            $continue = true;
        } else {
            $continue = false;
        }
        if ($cur === null) { continue; }
        if ($continue) { continue; }
        if (preg_match('/^- LOCATIONS(?:\s*\([^)]*\))?:\s*(.*)$/u', $line, $m)) { $bucket = 'loc';  $cur['loc']  .= ' ' . $m[1]; continue; }
        if (preg_match('/^- RISK\/DIVERGENCE:\s*(.*)$/u', $line, $m))           { $bucket = 'risk'; $cur['risk'] .= ' ' . $m[1]; continue; }
        if (preg_match('/^- (EVIDENCE|PROPOSAL):/u', $line))                     { $bucket = null; continue; }
        if ($bucket === 'loc')  { $cur['loc']  .= ' ' . $line; }
        if ($bucket === 'risk') { $cur['risk'] .= ' ' . $line; }
    }
    $closeCur();

    $findings = [];
    foreach ($raw as $e) {
        $parts = array_map('trim', preg_split('/\s+—\s+/u', $e['title']) ?: [$e['title']]);
        $label = mb_strtolower($parts[1] ?? '');
        $cls   = '??';
        foreach (MY_TYPE_CLASS_RULES as [$c, $needles]) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($label, $needle)) { $cls = $c; break 2; }
            }
        }
        $members = $e['loc'] === '' ? [] : myExtractMembers($e['loc'], $manifest);
        $driftMarkers = substr_count($e['risk'], '⚠');
        $claim = $parts[2] ?? null;
        $findings[] = [
            'id'      => ($e['section'] ?? 'S?') . '-' . $e['num'],
            'class'   => $cls,
            'title'   => $parts[0] ?? $e['title'],
            'label'   => $label,
            'claim'   => $claim, // size claim kept as STRING: claims are claims, not truth
            'members' => $members,
            'drift'   => $driftMarkers > 0,
            'drift_warning_markers' => $driftMarkers,
            'needs_llm_pass' => myNeedsLlm((string)($claim ?? ''), $members),
        ];
    }
    return $findings;
}

/**
 * Walk a LOCATIONS line: track the last absolute directory, expand braces and
 * multi-ranges. Relative continuation tokens are flagged with their origin.
 *
 * @param array<string,true>|null $manifest
 * @return list<array<string,mixed>>
 */
function myExtractMembers(string $loc, ?array $manifest): array
{
    $members = [];
    $prefix  = ''; // last-seen absolute directory, with trailing slash
    foreach (explode(';', $loc) as $chunk) {
        if (!preg_match_all('/([A-Za-z0-9_.,{}\/-]+\.php)((?::[0-9,\-+~\s]+[0-9])?)/u', $chunk, $toks, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($toks as $t) {
            $pathTok = $t[1];
            $ranges  = myParseRanges($t[2] ?? '');
            foreach (myExpandBraces($pathTok) as $p) {
                $origin = 'explicit';
                if (str_starts_with($p, '/')) {
                    $prefix = rtrim((string)preg_replace('#/[^/]*$#', '', $p), '/') . '/';
                } else {
                    $origin = 'relative';
                    $p = $prefix . $p;
                }
                [$rel, $verified] = myResolvePath($p, $manifest);
                if (preg_match('/[{},]/', $rel)) {
                    continue; // unbalanced-brace prose residue: never a real path —
                              // LLM pass owns such expansions (they show as zero-member)
                }
                if ($ranges === []) {
                    $members[] = ['file' => $rel, 'start' => null, 'end' => null,
                                  'origin' => $origin, 'verified' => $verified];
                } else {
                    foreach ($ranges as [$s, $e]) {
                        $members[] = ['file' => $rel, 'start' => $s, 'end' => $e,
                                      'origin' => $origin, 'verified' => $verified];
                    }
                }
            }
        }
    }
    return $members;
}

/** Parse ":33-50+69-86" / ":25-42,71-88" / ":32+76" / ":41" suffix → range pairs. @return list<array{0:int,1:int}> */
function myParseRanges(string $suffix): array
{
    $suffix = ltrim(trim($suffix, " \t~"), ':');
    if ($suffix === '') { return []; }
    $out = [];
    foreach (preg_split('/[+,]/', $suffix) ?: [] as $piece) {
        $piece = trim($piece, " ~");
        if ($piece === '') { continue; }
        if (preg_match('/^(\d+)-(\d+)$/', $piece, $m)) {
            $out[] = [(int)$m[1], (int)$m[2]];
        } elseif (preg_match('/^(\d+)$/', $piece, $m)) {
            $out[] = [(int)$m[1], (int)$m[1]];
        }
    }
    return $out;
}

/** @return list<string> */
function myExpandBraces(string $path): array
{
    if (!preg_match('/\{([^}]*)\}/', $path, $m)) {
        return [$path];
    }
    $at   = (int)strpos($path, $m[0]);
    $pre  = substr($path, 0, $at);
    $post = substr($path, $at + strlen($m[0]));
    $out  = [];
    foreach (explode(',', $m[1]) as $alt) {
        foreach (myExpandBraces($pre . trim($alt) . $post) as $expanded) {
            $out[] = $expanded;
        }
    }
    return $out;
}

/**
 * Normalize an extracted path and (when a manifest is given) verify it.
 * Direct relative match first, then unique-suffix re-anchor (relative
 * continuation paths like `App/Vps/Stop.php` land on include/App/... that way).
 *
 * @param array<string,true>|null $manifest @return array{0:string,1:?bool}
 */
function myResolvePath(string $p, ?array $manifest): array
{
    if ($manifest === null) { return [$p, null]; }
    $rel = str_starts_with($p, MY_ABS_PREFIX) ? substr($p, strlen(MY_ABS_PREFIX)) : $p;
    if (isset($manifest[$rel])) { return [$rel, true]; }
    $needle = '/' . ltrim($rel, '/');
    $hits = [];
    foreach ($manifest as $candidate => $_) {
        if (str_ends_with($candidate, $needle)) { $hits[] = $candidate; }
    }
    if (count($hits) === 1) { return [$hits[0], true]; }
    return [$rel, false];
}

/**
 * Honest gap flag for the LLM pass: the claim announces N copies but the
 * mechanical extraction found fewer ranged members.
 * @param list<array<string,mixed>> $members @return array<string,mixed>
 */
function myNeedsLlm(string $claim, array $members): array
{
    if (preg_match('/(\d+)\s*(?:copies|pairs|sites|matches|files)/u', $claim, $m)) {
        $claimed = (int)$m[1];
        $ranged  = count(array_filter($members, static fn(array $x): bool => $x['start'] !== null));
        if ($claimed > 0 && $ranged < $claimed) {
            return ['claimed_copies' => $claimed, 'ranged_members' => $ranged, 'gap' => true];
        }
    }
    return ['gap' => false];
}

/** @param list<array<string,mixed>> $findings */
function printStats(array $findings): void
{
    $classes = []; $members = 0; $noRange = 0; $withMembers = 0; $drift = 0; $warn = 0; $gaps = 0;
    $unverified = 0; $relativ = 0;
    foreach ($findings as $f) {
        $classes[$f['class']] = ($classes[$f['class']] ?? 0) + 1;
        $drift += $f['drift'] ? 1 : 0;
        $warn  += $f['drift_warning_markers'];
        $gaps  += ($f['needs_llm_pass']['gap'] ?? false) ? 1 : 0;
        if ($f['members'] !== []) { $withMembers++; }
        foreach ($f['members'] as $mm) {
            $members++;
            if ($mm['start'] === null) { $noRange++; }
            if ($mm['verified'] === false) { $unverified++; }
            if ($mm['origin'] === 'relative') { $relativ++; }
        }
    }
    ksort($classes);
    printf("entries: %d\n", count($findings));
    echo "class split: " . json_encode($classes) . "\n";
    printf("entries with >=1 member token: %d\n", $withMembers);
    printf("member tokens: %d (rangeless: %d)\n", $members, $noRange);
    printf("relative-origin tokens: %d | unverified-after-resolve: %d\n", $relativ, $unverified);
    printf("drift entries: %d (total warning markers: %d)\n", $drift, $warn);
    printf("claim-gap entries (LLM-pass candidates): %d\n", $gaps);
}
