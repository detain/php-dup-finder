<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud checks of the DupBanner logo against the owner's hard
 * constraints. Exit non-zero on ANY violation.
 *
 *   php verify.php
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Branding\DupBanner;

$fail = 0;

/** @param array<array-key, mixed> $ctx */
function check(bool $ok, string $msg, array $ctx = []): void
{
    global $fail;
    if ($ok) {
        echo "  PASS  $msg\n";
        return;
    }
    $fail++;
    echo "  FAIL  $msg" . ($ctx !== [] ? '  ' . json_encode($ctx) : '') . "\n";
}

/**
 * Visible widths (cols) of each painted row of a frame.
 *
 * @return list<int>
 */
function widths(string $frame): array
{
    $w = [];
    foreach (preg_split("/\n/", rtrim($frame, "\n")) ?: [] as $l) {
        $w[] = mb_strlen(preg_replace('/\e\[[0-9;]*m/', '', $l) ?? '');
    }
    return $w;
}

/**
 * Right-most non-blank column across rows.
 *
 * @param list<string> $frames
 */
function maxContentWidth(array $frames): int
{
    $max = 0;
    foreach ($frames as $f) {
        foreach (explode("\n", rtrim($f, "\n")) as $l) {
            $vis = rtrim(preg_replace('/\e\[[0-9;]*m/', '', $l) ?? '');
            $max = max($max, mb_strlen($vis));
        }
    }
    return $max;
}

$tc = new DupBanner('tc');
$x256 = new DupBanner('256');
$plain = new DupBanner('plain');
$final = DupBanner::TICKS - 1;

echo "1. Budget (<=1.5s, <=30fps, <=45 ticks)\n";
check(DupBanner::TICKS <= 45, 'ticks <= 45', ['ticks' => DupBanner::TICKS]);
check(DupBanner::TICKS * DupBanner::TICK_MS <= 1500, 'playback <= 1500 ms',
    ['ms' => DupBanner::TICKS * DupBanner::TICK_MS]);
check(1000 / DupBanner::TICK_MS <= 30.0001, 'fps <= 30', ['fps' => round(1000 / DupBanner::TICK_MS, 2)]);

echo "\n2. Same-process determinism\n";
check($tc->frame(0) === $tc->frame(0), 'tc frame(0) stable on repeat');
check($tc->frame($final) === $tc->frame($final), 'tc final frame stable on repeat');
check($x256->frame(0) === $x256->frame(0), '256 frame(0) stable');
check($tc->frame(9999) === $tc->frame($final), 'over-range tick holds final');

echo "\n3. Cross-process byte determinism (tick 0 + final)\n";
$expr = static function (string $depth, int $tick): string {
    return 'require __DIR__.\'/DupBanner.php\';'
        . '$b=new Phpdup\\Branding\\DupBanner(\'' . $depth . '\');'
        . 'echo md5($b->frame(' . $tick . '));';
};
foreach ([['tc', 0], ['tc', $final], ['256', 0], ['256', $final], ['plain', $final]] as [$d, $t]) {
    $a = trim((string) shell_exec('php -r ' . escapeshellarg($expr($d, $t))));
    $b = trim((string) shell_exec('php -r ' . escapeshellarg($expr($d, $t))));
    check($a !== '' && $a === $b, "$d tick $t md5 stable across processes", ['a' => $a, 'b' => $b]);
}

echo "\n4. Size caps (final <= 7 rows x <= 56 cols; content fits at every stage)\n";
$fw = widths($tc->frame($final));
check(count($fw) === DupBanner::ROWS, 'final rows == 7', ['rows' => count($fw)]);
check(max($fw) <= 56, 'final cols <= 56', ['max' => max($fw)]);
$all = [];
for ($t = 0; $t < DupBanner::TICKS; $t++) {
    $all[] = $tc->frame($t);
}
$mc = maxContentWidth($all);
check($mc <= 56, 'snippet/status content never exceeds 56 cols', ['max' => $mc]);
$over80 = [];
foreach ($all as $t => $f) {
    foreach (explode("\n", $f) as $l) {
        if (mb_strlen(preg_replace('/\e\[[0-9;]*m/', '', $l) ?? '') > 80) {
            $over80[] = $t;
        }
    }
}
check($over80 === [], 'no painted row overflows 80 cols', $over80);

echo "\n5. Fallback chain\n";
$pp = $plain->plain();
check(!str_contains($pp, "\e"), 'plain fallback: ZERO escape codes');
check(count(preg_split("/\n/", rtrim($pp, "\n")) ?: []) <= 7, 'plain <= 7 rows');
check(max(array_map('mb_strlen', preg_split("/\n/", rtrim($pp, "\n")) ?: [])) <= 56, 'plain <= 56 cols');
check(str_contains($tc->frame($final), '38;2;'), 'tc frame uses 24-bit fg SGR');
check(str_contains($tc->frame(12), '48;2;'), 'tc frame uses 24-bit bg SGR (beam band)');
check($tc->depth() === 'tc' && $plain->depth() === 'plain', 'depth accessor');
$thrown = false;
try {
    new DupBanner('cmyk');
} catch (InvalidArgumentException) {
    $thrown = true;
}
check($thrown, 'unknown depth fails fast');

echo "\n6. 256-colour path is valid SGR only\n";
preg_match_all('/\e\[[0-9;?]*[A-Za-z]?/', $x256->play(), $m);
$bad = [];
foreach ($m[0] as $seq) {
    if (preg_match('/^\e\[([0-9;]*)m$/', $seq, $mm)) {
        $p = $mm[1] === '' ? [0] : array_map('intval', explode(';', $mm[1]));
        for ($i = 0; $i < count($p); $i++) {
            $v = $p[$i];
            if ($v === 0 || $v === 39 || $v === 49) {
                continue;
            }
            if (($v === 38 || $v === 48) && ($p[$i + 1] ?? -1) === 5
                && isset($p[$i + 2]) && $p[$i + 2] >= 0 && $p[$i + 2] <= 255) {
                $i += 2;
                continue;
            }
            $bad[] = $seq;
            break;
        }
    } elseif (preg_match('/^\e\[\d+A$/', $seq)) {
        continue; // CUU cursor-up frame join - legal
    } elseif (!preg_match('/^\e\[\?25[hl]$/', $seq)) {
        $bad[] = $seq;
    }
}
check($bad === [], 'every 256-stream sequence is a legal SGR / cursor code', array_slice($bad, 0, 3));
check(!str_contains($x256->play(), '38;2;'), '256 stream contains no truecolor codes');

echo "\n7. Real-Normalizer fidelity (the merge premise)\n";
$data = require __DIR__ . '/normalized.php';
check(
    $data['modes']['aggressive']['A'] === $data['modes']['aggressive']['B'],
    'the two normalized lines end TRULY IDENTICAL (aggressive)',
    ['a' => $data['modes']['aggressive']['A'], 'b' => $data['modes']['aggressive']['B']],
);
check($data['snippets']['A'] !== $data['snippets']['B'], 'the source snippets look different');
foreach (['strict', 'default', 'aggressive'] as $mode) {
    check(
        strlen($data['modes'][$mode]['A']) <= 55 && strlen($data['modes'][$mode]['B']) <= 55,
        "$mode line fits in 56 cols",
        ['a' => strlen($data['modes'][$mode]['A']), 'b' => strlen($data['modes'][$mode]['B'])],
    );
}
check(str_contains($data['modes']['strict']['A'], '__V0'), 'strict really returns __V0 tokens');
check(str_contains($data['modes']['default']['A'], '__STR'), 'default really returns __STR');
check(str_contains($data['modes']['aggressive']['A'], '__CALL0'), 'aggressive really returns __CALL0');
check(str_contains($data['modes']['aggressive']['A'], '__PROP0'), 'aggressive really returns __PROP0');
check(str_contains($data['modes']['aggressive']['A'], '__CLASS0'), 'aggressive really returns __CLASS0');
// final frame paints the skeleton from this same data
$fin = preg_replace('/\e\[[0-9;]*m/', '', $tc->frame(DupBanner::BEAM_ENDS[2])) ?? '';
check(str_contains($fin, '__CLASS0::__CALL0'), 'the painted beam-3 end state shows the harvested skeleton');

echo "\n";
if ($fail > 0) {
    fwrite(STDERR, "VERIFY FAILED: $fail check(s)\n");
    exit(1);
}
echo "ALL CHECKS PASSED\n";
