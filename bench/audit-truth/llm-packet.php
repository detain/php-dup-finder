<?php
/**
 * bench/audit-truth/llm-packet.php — emit the LLM-pass work packet.
 *
 * Reads the two derived-truth payloads (from extract-my.php /
 * extract-phlix.php — operator dir, OUTSIDE the repo, privacy gate) and writes
 * JSONL: one summary line + one line per finding that the mechanical pass could
 * not fully close, tagged with the `reasons` documented in LLM-PASS.md.
 *
 * Pure local transform: NO network, NO external LLM/API calls. The operator
 * feeds the packet to whatever pass they run (LLM-PASS.md is the work order).
 *
 * Usage:
 *   php bench/audit-truth/llm-packet.php --my=/tmp/out/my.truth.json
 *       --phlix=/tmp/out/phlix.truth.json --out=/tmp/out/llm-packet.jsonl
 */
declare(strict_types=1);

$opts = getopt('', ['my::', 'phlix::', 'out::']);
foreach (['my', 'phlix', 'out'] as $req) {
    if (!isset($opts[$req]) || !is_string($opts[$req]) || $opts[$req] === '') {
        fwrite(STDERR, "[err] --{$req} is required (see header)\n");
        exit(2);
    }
}

$lines = [];
$counts = [];
foreach (['my' => (string)$opts['my'], 'phlix' => (string)$opts['phlix']] as $corpus => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[err] truth not readable: {$path}\n");
        exit(2);
    }
    $payload = json_decode((string)file_get_contents($path), true);
    if (!is_array($payload) || ($payload['schema'] ?? '') !== 'audit-truth/1') {
        fwrite(STDERR, "[err] not an audit-truth/1 payload: {$path}\n");
        exit(2);
    }
    foreach ($payload['findings'] as $f) {
        $reasons  = [];
        $members  = $f['members'] ?? [];
        $unres    = $f['needs_llm_pass']['unresolved_tokens'] ?? [];
        $rangeless = 0;
        foreach ($members as $m) {
            if ($m['start'] === null) { $rangeless++; }
        }
        if ($members === []) { $reasons[] = 'prose-only'; }
        if ($unres !== [])   { $reasons[] = 'unresolved-cites'; }
        if ($rangeless > 0)  { $reasons[] = 'rangeless'; }
        if ($f['class'] === '??') { $reasons[] = 'class-unmapped'; }
        if (($f['needs_llm_pass']['gap'] ?? false) === true) { $reasons[] = 'claim-gap'; }
        if ($reasons === []) { continue; }
        foreach ($reasons as $r) { $counts[$r] = ($counts[$r] ?? 0) + 1; }
        $lines[] = json_encode([
            'corpus'         => $corpus,
            'id'             => $f['id'],
            'class'          => $f['class'],
            'title'          => $f['title'],
            'reasons'        => $reasons,
            'members'        => count($members),
            'rangeless'      => $rangeless,
            'unresolved'     => $unres,
            'claim'          => $f['claim'] ?? null,
            'claimed_copies' => $f['needs_llm_pass']['claimed_copies'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if ($corpus === 'phlix') {
        $gap = (int)($payload['doc_claimed_findings'] ?? 0) - (int)($payload['heads_parsed'] ?? 0);
        if ($gap > 0) {
            $counts['summary-table-heads'] = $gap;
            $lines[] = json_encode([
                'corpus' => 'phlix', 'id' => '<summary-table>', 'class' => null,
                'title'  => "{$gap} findings exist only in the §1–§6 summary tables (no dossier head)",
                'reasons' => ['summary-table-heads'],
            ], JSON_UNESCAPED_SLASHES);
        }
    }
}

$lines[] = json_encode(['summary' => $counts, 'records' => count($lines)], JSON_PRETTY_PRINT);
if (file_put_contents((string)$opts['out'], implode("\n", $lines) . "\n") === false) {
    fwrite(STDERR, "[err] cannot write {$opts['out']} (packet is derived material — keep it OUTSIDE the repo)\n");
    exit(2);
}
echo '[ok] wrote ' . $opts['out'] . ' (' . count($lines) . " lines)\n";
foreach ($counts as $r => $n) { printf("  %-22s %d\n", $r, $n); }
