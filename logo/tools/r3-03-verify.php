<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud constraint gate for the LSH Jackpot banner.
 * Exits 1 on any violation. Run: php verify.php
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
    echo '  FAIL  ' . $msg . ($ctx !== [] ? '  ' . json_encode($ctx) : '') . "\n";
}

/** Visible width of each line (escapes stripped). @return list<int> */
function widths(string $frame): array
{
    $out = [];
    foreach (explode("\n", $frame) as $l) {
        $out[] = mb_strlen(preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $l) ?? '');
    }
    return $out;
}

$final = DupBanner::TICKS - 1;

echo "1. Banding story (MinHashSignature::ROWS_PER_BAND = " . DupBanner::BAND_ROWS . ")\n";
$full = 0;
$near = 0;
$hits = [];
for ($b = 0; $b < DupBanner::BANDS; $b++) {
    $m = DupBanner::bandMatches($b);
    $hits[$b] = $m;
    if ($m === DupBanner::BAND_ROWS) {
        $full++;
    } elseif ($m === DupBanner::BAND_ROWS - 1) {
        $near++;
    }
}
check(count(DupBanner::SIG_A) === 16 && count(DupBanner::SIG_B) === 16, 'signatures are 16 reels deep');
check($full === 1, 'EXACTLY one full-band match', ['per-band' => $hits]);
check($near === 1, 'EXACTLY one 3-of-4 near miss', ['per-band' => $hits]);
check(DupBanner::bandMatches(DupBanner::JACKPOT_BAND) === 4, 'jackpot band is the 4/4 band');
check(DupBanner::bandMatches(DupBanner::NEARMISS_BAND) === 3, 'near-miss band is the 3/4 band');

echo "\n2. Determinism (separate php processes)\n";
$expr = static fn (string $mode, int $tick): string =>
    'require "' . __DIR__ . '/DupBanner.php";'
    . 'echo md5(\Phpdup\Branding\DupBanner::frame(' . $tick . ",'$mode'));";
foreach ([['tc', 0], ['tc', $final], ['256', 0], ['256', $final]] as [$m, $t]) {
    $a = trim((string) shell_exec('php -r ' . escapeshellarg($expr($m, $t))));
    $b = trim((string) shell_exec('php -r ' . escapeshellarg($expr($m, $t))));
    check($a !== '' && $a === $b, "$m tick $t md5 stable across processes", ['a' => $a, 'b' => $b]);
}
check(DupBanner::frame(0, 'tc') === DupBanner::frame(0, 'tc'), 'in-process frame replay byte-identical');
$playA = DupBanner::play('tc');
$playB = DupBanner::play('tc');
check($playA === $playB, 'play() byte-reproducible');
$savedTc = @file_get_contents(__DIR__ . '/logo.ansi');
check($savedTc === false || $savedTc === $playA, 'logo.ansi on disk == play() (build in sync)');

echo "\n3. Budget + size caps\n";
check(DupBanner::TICKS <= 45, 'ticks <= 45', ['ticks' => DupBanner::TICKS]);
check(DupBanner::TICKS * DupBanner::TICK_MS <= 1500, 'wall budget <= 1.5 s', ['ms' => DupBanner::TICKS * DupBanner::TICK_MS]);
check(1000 / DupBanner::TICK_MS <= 30.4, 'fps <= 30', ['ms' => DupBanner::TICK_MS]);
$fw = widths(DupBanner::frame($final, 'tc'));
check(count($fw) === DupBanner::ROWS, 'final frame is 7 rows', ['rows' => count($fw)]);
check(max($fw) <= 56, 'final frame cols <= 56', ['max' => max($fw)]);
check(max($fw) === DupBanner::COLS, 'occupancy uniform at 53 cols', ['max' => max($fw), 'min' => min($fw)]);
$over = [];
foreach ([0, 15, 26, 30, $final] as $t) {
    foreach (widths(DupBanner::frame($t, 'tc')) as $y => $w) {
        if ($w > 56) {
            $over[] = "t$t/y$y:$w";
        }
    }
}
check($over === [], 'spot ticks never exceed 56 cols (80-col safe)', $over);

echo "\n4. Fallback chain\n";
$plain = DupBanner::frame($final, 'plain');
check(!str_contains($plain, "\e"), 'plain frame contains ZERO escape codes');
check(!str_contains(DupBanner::play('plain'), "\e"), 'plain stream contains ZERO escape codes');
check(preg_match('/[\x{2502}\x{2500}\x{2550}]/u', $plain) === 0, 'plain swaps box glyphs for ASCII');
check(str_contains(DupBanner::frame($final, 'tc'), '48;2;'), 'tc emits 24-bit bg SGR');
check(DupBanner::resolveMode(true, ['COLORTERM' => 'truecolor']) === 'tc', 'resolve: truecolor -> tc');
check(DupBanner::resolveMode(true, ['COLORTERM' => '24bit']) === 'tc', 'resolve: 24bit -> tc');
check(DupBanner::resolveMode(true, ['TERM' => 'xterm-256color']) === '256', 'resolve: no COLORTERM -> 256');
check(DupBanner::resolveMode(true, ['NO_COLOR' => '1', 'COLORTERM' => 'truecolor']) === 'plain', 'resolve: NO_COLOR wins');
check(DupBanner::resolveMode(false, ['COLORTERM' => 'truecolor']) === 'plain', 'resolve: undecorated -> plain');

echo "\n5. 256-colour stream is valid SGR only\n";
preg_match_all('/\e\[[0-9;]*m/', DupBanner::play('256'), $m);
$bad = [];
foreach ($m[0] as $seq) {
    if (preg_match('/^\e\[(38|48);5;(\d{1,3})m$/', $seq, $mm) && (int) $mm[2] <= 255) {
        continue;
    }
    if ($seq === "\e[0m") {
        continue;
    }
    $bad[] = $seq;
}
check($bad === [], 'every 256 SGR is 0 or 38;5;N / 48;5;N (N<=255)', array_slice($bad, 0, 5));
$x = DupBanner::play('256');
check(!str_contains($x, '38;2;') && !str_contains($x, '48;2;'), '256 stream emits no truecolor SGR');

echo "\n6. Final frame tells the story\n";
$tcFinal = DupBanner::frame($final, 'tc');
check(str_contains($tcFinal, '38;2;250;204;21'), 'jackpot frame is gold #FACC15');
check(str_contains($tcFinal, '38;2;194;94;120'), 'near-miss frame is settled pink');
check(str_contains($tcFinal, '48;2;30;27;75'), 'reel face #1E1B4B present (as cell bg)');
$strip = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $tcFinal) ?? '';
check(str_contains($strip, 'phpdup'), 'wordmark revealed');
check(str_contains($strip, '4/4 rows collide -> candidate pair'), 'tagline revealed');
check(str_contains($strip, '6a 2b c8 93'), 'jackpot band digits settled on both rows (A)');
check(str_contains($strip, 'e3 5a 6f 9d'), 'band-0 mismatches visible (B)');
$same = true;
$ctx = [];
foreach (explode("\n", $strip) as $i => $line) {
    foreach (['│' => '|', '─' => '-', '═' => '='] as $u => $ascii) {
        $line = str_replace($u, $ascii, $line);
    }
    $pl = explode("\n", $plain)[$i] ?? '';
    if (rtrim($line) !== rtrim($pl)) {
        $same = false;
        $ctx = ['row' => $i, 'tc' => rtrim($line), 'plain' => rtrim($pl)];
    }
}
check($same, 'tc and plain share the same occupancy/text', $ctx);

echo "\n";
if ($fail > 0) {
    fwrite(STDERR, "VERIFY FAILED: $fail assertion(s)\n");
    exit(1);
}
echo "ALL CHECKS PASSED\n";
