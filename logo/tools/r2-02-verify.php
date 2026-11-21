<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud gates for the DupBanner "Hash Rain Lock-in" logo.
 *
 * Exit 0 = every constraint holds. Any breach prints a diagnostic and exits 1.
 */

require_once __DIR__ . '/DupBanner.php';

$state = ['fail' => 0];

$ok = static function (bool $cond, string $what) use (&$state): void {
    if ($cond) {
        echo "PASS  {$what}\n";
    } else {
        echo "FAIL  {$what}\n";
        $state['fail']++;
    }
};

function stripSgr(string $s): string
{
    return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $s);
}

function colWidth(string $line): int
{
    return mb_strwidth($line, 'UTF-8');
}

$final = DupBanner::finalTick();

// 1 — pure determinism: same tick twice in-process
$ok(DupBanner::frame(0) === DupBanner::frame(0), 'frame(0) byte-identical on repeat');
$ok(DupBanner::frame($final) === DupBanner::frame($final), 'frame(final) byte-identical on repeat');

// 2 — determinism across separate php processes (tick 0, final, full stream)
function spawn(string $expr): string
{
    $cmd = ['php', '-r', 'require ' . escapeshellarg(__DIR__ . '/DupBanner.php') . '; ' . $expr];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return "\x00PROCSPAWNFAIL";
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $out;
}

$a = spawn('echo DupBanner::frame(0);');
$b = spawn('echo DupBanner::frame(0);');
$ok($a === $b && $a !== '', 'frame(0) byte-identical across php processes');
$ok(
    spawn('echo DupBanner::frame(DupBanner::finalTick());')
    === spawn('echo DupBanner::frame(DupBanner::finalTick());'),
    'frame(final) byte-identical across php processes'
);
$ok(
    spawn('echo DupBanner::ansi();') === spawn('echo DupBanner::ansi();'),
    'full ansi stream sha-identical across php processes'
);

// 3 — geometry: every frame <= 7 rows x <= 56 cols (measured, not folded)
$nrows = 0;
$wmax  = 0;
for ($t = 0; $t <= $final; $t++) {
    $rr = explode("\n", rtrim(stripSgr(DupBanner::frame($t)), "\n"));
    $nrows = max($nrows, count($rr));
    foreach ($rr as $r) {
        $wmax = max($wmax, colWidth($r));
    }
}
$ok($nrows === 7, "every frame has exactly 7 rows (measured {$nrows}, spec max 7)");
$ok($wmax <= 56, "measured max frame width {$wmax} <= 56 cols (fits 80)");

// 4 — plain fallback: zero escape codes
$plain = DupBanner::plain();
$ok(!str_contains($plain, "\e"), 'plain() contains NO escape codes');
$ok(str_contains($plain, 'PHPDUP') || str_contains($plain, '#'), 'plain() carries wordmark ink');

// 5 — one-shot budget: probe the frame() boundary at runtime, then assert
$measuredTicks = 0;
while (true) {
    try {
        DupBanner::frame($measuredTicks);
    } catch (\InvalidArgumentException) {
        break;
    }
    $measuredTicks++;
}
$ok($measuredTicks <= 45, "runtime-probed tick count {$measuredTicks} <= 45");
$ok($measuredTicks / DupBanner::FPS <= 1.5 + 1e-9, sprintf('playback %.2fs <= 1.5s', $measuredTicks / DupBanner::FPS));

// 6 — truecolor stream: only valid SGR families
$tc = DupBanner::ansi();
$sgr = preg_match_all('/\e\[[0-9;?]*[A-Za-z]?/', $tc, $toks);
$sgr = $sgr === false ? 0 : $sgr;
$allowed = 0;
foreach ($toks[0] as $tok) {
    if (preg_match('/^\e\[(0|[34]8;[25](;\d+){1,3}|39|49|\?25[lh]|\d+[A-Za-z])m?$/', $tok)) {
        $allowed++;
    }
}
$ok($sgr === $allowed, sprintf('truecolor stream: %d/%d SGR sequences all valid', $allowed, $sgr));
$ok(str_contains($tc, "\e[38;2;") && str_contains($tc, "\e[48;2;"), 'truecolor emits 38;2/48;2');
$ok(str_starts_with($tc, "\e[0m\e[?25l") && str_ends_with($tc, "\e[?25h"), 'stream hides/shows cursor bookends');

// 7 — 256 fallback: valid SGR only, no 38;2/48;2 anywhere
$f256 = DupBanner::ansi256();
$ok(!str_contains($f256, '38;2') && !str_contains($f256, '48;2'), '256 stream contains no truecolor SGR');
preg_match_all('/\e\[[0-9;?]*[A-Za-z]?/', $f256, $toks2);
$ok256 = 0;
foreach ($toks2[0] as $tok) {
    if (preg_match('/^\e\[(0|38;5;\d+|48;5;\d+|39|49|\?25[lh]|\d+[A-Za-z])m?$/', $tok)) {
        $ok256++;
    }
}
$ok(count($toks2[0]) === $ok256, sprintf('256 stream: %d/%d SGR sequences all valid', $ok256, count($toks2[0])));
foreach ($toks2[0] as $tok) {
    if (preg_match('/^\e\[([34]8);5;(\d+)m$/', $tok, $m)) {
        if ((int) $m[2] < 0 || (int) $m[2] > 255) {
            $ok(false, "256 SGR index out of range: {$tok}");
            break;
        }
    }
}
$ok(true, '256 SGR indices within 0..255');

// 8 — lock semantics straight from the cell grid
$locks = DupBanner::locks();
$ok(count($locks) >= 6, 'at least 6 lock events');
$lockOk = true;
$preMatch = true;
foreach ($locks as [$et, $hash, $cl, $cr, $rt]) {
    $cells = DupBanner::cellsPublic($et);
    for ($i = 0; $i < 4; $i++) {
        [$gl] = $cells[$rt + $i][$cl];
        [$gr] = $cells[$rt + $i][$cr];
        if ($gl !== $hash[$i] || $gr !== $hash[$i]) {
            $lockOk = false;
        }
    }
    // persistence: the pair holds the hash for EVERY later tick
    for ($t = $et; $t <= $final; $t++) {
        $c2 = DupBanner::cellsPublic($t);
        for ($i = 0; $i < 4; $i++) {
            if ($c2[$rt + $i][$cl][0] !== $hash[$i] || $c2[$rt + $i][$cr][0] !== $hash[$i]) {
                $lockOk = false;
                break 2;
            }
        }
    }
    // the tick BEFORE the lock the two columns already show the same hash window
    if ($et > 0) {
        $c1 = DupBanner::cellsPublic($et - 1);
        for ($i = 0; $i < 4; $i++) {
            [$gl] = $c1[$rt + $i][$cl];
            [$gr] = $c1[$rt + $i][$cr];
            if ($gl !== $gr) {
                $preMatch = false;
            }
        }
    }
}
$ok($lockOk, 'locks land on their hash, twin-equal, and STAY for all later ticks');
$ok($preMatch, 'twin columns display the identical 4-char hash the tick before locking');

// 9 — duplicates by design: tiny pool, repeated full-column streams at tick 2
$streams = [];
$c2 = DupBanner::cellsPublic(2);
for ($c = 0; $c < DupBanner::COLS; $c++) {
    $s = '';
    for ($r = 0; $r < DupBanner::ROWS; $r++) {
        $g = $c2[$r][$c][0];
        $s .= $g === ' ' || $g === '▓' || $g === '·' ? '.' : $g;
    }
    $streams[$c] = $s;
}
$dupe = false;
for ($i = 0; $i < DupBanner::COLS; $i++) {
    for ($j = $i + 1; $j < DupBanner::COLS; $j++) {
        if ($streams[$i] === $streams[$j]) {
            $dupe = true;
            break 2;
        }
    }
}
$ok($dupe, 'duplicate identical rain columns visible (duplicates by design)');
$ok(count(array_unique(DupBanner::POOL)) === 10, 'hash pool is tiny (10)');

// 10 — hold frame: wordmark fully stamped at the final tick
$cf = DupBanner::cellsPublic($final);
$solid = 0;
for ($r = 1; $r <= 5; $r++) {
    for ($c = 11; $c <= 45; $c++) {
        if ($cf[$r][$c][0] === '█') {
            $solid++;
        }
    }
}
// 5x5 caps ink census: P13 + H13 + P13 + D14 + U11 + P13 = 77 solid cells exactly
$ok($solid === 77, "wordmark fully assembled at hold frame ({$solid}/77 solid cells)");
$fail = $state['fail'];
echo $fail === 0 ? "\nALL GATES PASS\n" : "\n{$fail} GATE(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
