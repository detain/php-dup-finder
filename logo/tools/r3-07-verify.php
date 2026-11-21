<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud constraint gates for the Call-Site Rewire logo.
 * Run: php verify.php   (exit 0 + "ALL GATES PASS" only when everything holds)
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Support\DupBanner;

$fails = [];
$assert = static function (bool $ok, string $what) use (&$fails): void {
    if (!$ok) {
        $fails[] = $what;
        fwrite(STDERR, "FAIL: {$what}\n");
    } else {
        echo "pass: {$what}\n";
    }
};

$b = new DupBanner();

/** stripped glyph grid of a colored frame: list<list<string>> of codepoints */
$charGrid = static function (string $frame): array {
    $stripped = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $frame) ?? '';
    return array_map('mb_str_split', explode("\n", rtrim($stripped, "\n")));
};

// -------------------------------------------------- determinism (in-process)
$assert($b->frame(0) === $b->frame(0), 'frame(0) byte-identical when called twice');
$assert(
    $b->frame(DupBanner::FINAL_TICK) === $b->frame(DupBanner::FINAL_TICK),
    'final frame byte-identical when called twice'
);

// -------------------------------------------------- determinism (separate php runs)
$cmp = static function (int $tick) use ($b, $assert): void {
    $inproc = $b->frame($tick);
    $php = '<?php require ' . var_export(__DIR__ . '/DupBanner.php', true)
        . '; echo (new Phpdup\\Support\\DupBanner())->frame(' . $tick . ');';
    $tmp = tempnam(sys_get_temp_dir(), 'dupv');
    if ($tmp === false) {
        throw new RuntimeException('cannot create temp file');
    }
    file_put_contents($tmp, $php);
    // cross-invocation determinism intentionally shells out to php
    // (ForbiddenCode suppressed per-file in psalm-lane.xml)
    $out1 = shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp));
    $out2 = shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp));
    unlink($tmp);
    if ($out1 === null || $out2 === null) {
        throw new RuntimeException('shell_exec failed for tick ' . $tick);
    }
    $assert($out1 === $out2, "tick {$tick} identical across two php runs");
    $assert($out1 === $inproc, "tick {$tick} matches in-process bytes");
};
$cmp(0);
$cmp(DupBanner::FINAL_TICK);

// -------------------------------------------------- budget: ticks + duration
$le = static fn (int|float $x, int|float $y): bool => $x <= $y;
$assert($le(DupBanner::TICKS, 45), 'tick count ' . DupBanner::TICKS . ' <= 45');
$assert($le(DupBanner::TICKS / DupBanner::FPS, 1.5), sprintf('duration %.3fs <= 1.5s', DupBanner::TICKS / DupBanner::FPS));
$assert($le(DupBanner::FPS, 30), 'fps ' . DupBanner::FPS . ' <= 30');

// -------------------------------------------------- phase order sanity
$order = [
    DupBanner::PULSE_START, DupBanner::PULSE_END, DupBanner::PROBE_START, DupBanner::PROBE_END,
    DupBanner::DESOLDER_START, DupBanner::DESOLDER_END, DupBanner::FOOTPRINT_TICK,
    DupBanner::ROUTE_START, DupBanner::ROUTE_END, DupBanner::VERIFY_END,
    DupBanner::GROW_START, DupBanner::GROW_FINAL_TICK, DupBanner::FAN_START,
    DupBanner::FAN_END, DupBanner::FINAL_TICK,
];
$sortedOrder = $order;
sort($sortedOrder);
$assert($order === $sortedOrder, 'phase map is chronologically ordered');
$assert(
    DupBanner::ROUTE_END - DupBanner::ROUTE_START + 1 === count(DupBanner::routeSegments()),
    'routing window is exactly one segment per tick (' . count(DupBanner::routeSegments()) . ' segments / '
        . (DupBanner::ROUTE_END - DupBanner::ROUTE_START + 1) . ' ticks)'
);

// -------------------------------------------------- geometry: every frame
$geomOk = true;
foreach (range(0, DupBanner::FINAL_TICK) as $t) {
    $grid = $charGrid($b->frame($t));
    if (count($grid) !== DupBanner::ROWS) {
        $geomOk = false;
        break;
    }
    foreach ($grid as $line) {
        if (count($line) > DupBanner::COLS || mb_strwidth(implode('', $line)) > DupBanner::COLS) {
            $geomOk = false;
            break 2;
        }
    }
}
$assert($geomOk, 'all ' . DupBanner::TICKS . ' frames: exactly ' . DupBanner::ROWS . ' rows, <= ' . DupBanner::COLS . ' cols');
$assert($le(DupBanner::COLS, 80), 'no horizontal overflow at 80 cols');

// -------------------------------------------------- precomputed route segments
$segments = DupBanner::routeSegments();
$assert(count($segments) === DupBanner::ROUTE_COUNT, 'routeSegments() yields ' . DupBanner::ROUTE_COUNT . ' segments');
$boxRunes = ['┌', '┐', '└', '┘', '─', '│', '┤', '├'];
$runesOk = true;
$cellsOk = true;
foreach ($segments as $seg) {
    if ($seg === []) {
        $cellsOk = false;
    }
    foreach ($seg as [$y, $x, $ch]) {
        if (!in_array($ch, $boxRunes, true) || $y < 0 || $y >= DupBanner::ROWS || $x < 0 || $x >= DupBanner::COLS) {
            $runesOk = false;
        }
    }
}
$assert($runesOk && $cellsOk, 'every segment cell is an in-bounds box-drawing rune');

$gridBefore = $charGrid($b->frame(DupBanner::ROUTE_START - 1));
$onePerTick = true;
foreach ($segments as $i => $seg) {
    $t = DupBanner::ROUTE_START + $i;
    $now = $charGrid($b->frame($t));
    $changed = [];
    for ($y = 0; $y < DupBanner::ROWS; $y++) {
        for ($x = 0; $x < DupBanner::COLS; $x++) {
            if ($gridBefore[$y][$x] !== $now[$y][$x]) {
                $changed[$y . ':' . $x] = $now[$y][$x];
            }
        }
    }
    $expected = [];
    foreach ($seg as [$y, $x, $ch]) {
        $expected[$y . ':' . $x] = $ch;
    }
    ksort($changed);
    ksort($expected);
    if ($changed !== $expected) {
        $onePerTick = false;
        fwrite(STDERR, sprintf(
            "  routing tick %d: expected %s, got %s\n",
            $t,
            json_encode($expected),
            json_encode($changed)
        ));
    }
    $gridBefore = $now;
}
$assert($onePerTick, 'routing renders exactly ONE precomputed segment per tick, nothing else (ticks '
    . DupBanner::ROUTE_START . '..' . DupBanner::ROUTE_END . ')');

// -------------------------------------------------- final rewired topology
$finalGrid = $charGrid($b->frame(DupBanner::FINAL_TICK));
$assert($finalGrid[4][DupBanner::CHIP_A] === '┌', 'left pad arm starts at ┌ on the surviving trace');
$assert($finalGrid[4][26] === '─' && $finalGrid[4][27] === '┤', 'left arm merges into centre trace at ┤');
$assert($finalGrid[5][46] === '┐', 'right pad arm starts at ┐');
$assert($finalGrid[5][28] === '─' && $finalGrid[5][27] === '├', 'right arm merges into centre trace at ├');
$assert($finalGrid[3][10] === ' ' && $finalGrid[3][46] === ' ' && $finalGrid[4][46] === ' ',
    'desoldered chip stubs are gone from the final board');
$assert(str_contains(implode('', $finalGrid[1]), 'phpdup'), 'final chip carries the phpdup wordmark');
$assert($finalGrid[6][10] === '●' && $finalGrid[6][27] === '●' && $finalGrid[6][46] === '●',
    'one chip fans out to three call-site pads in the final frame');

// -------------------------------------------------- hold: final is byte-stable + still
$rebuild = new DupBanner();
$assert($rebuild->frame(DupBanner::FINAL_TICK) === $b->frame(DupBanner::FINAL_TICK),
    'final frame identical from a fresh instance');
$assert($b->frame(DupBanner::FINAL_TICK) !== $b->frame(DupBanner::FAN_END),
    'the last pulse runs before the hold (tick 43 differs from final tick 44)');
$assert(!str_contains($b->frame(DupBanner::FINAL_TICK), "\e[38;2;253;186;116m"),
    'final frame is still: no pulse-head colour anywhere');

// -------------------------------------------------- plain fallback
$plain = $b->staticText();
$assert(!str_contains($plain, "\e"), 'plain fallback contains zero escape codes');
$assert(str_contains($plain, 'phpdup'), 'plain fallback shows the wordmark');
$assert($b->render(['NO_COLOR' => '1', 'COLORTERM' => 'truecolor']) === $plain, 'render() honors NO_COLOR');
$assert($b->render(['TERM' => 'dumb'], false) === $plain, 'undecorated / --no-ansi path returns plain');
$leak = -1;
foreach (range(0, DupBanner::FINAL_TICK) as $t) {
    if (str_contains($b->framePlain($t), "\e")) {
        $leak = $t;
        break;
    }
}
$assert($leak === -1, 'plain rendition escape-free for every tick');

// -------------------------------------------------- truecolor stream shape
$tc = $b->play();
$assert(str_contains($tc, "\e[38;2;"), 'truecolor stream emits 38;2 SGR');
$assert(str_contains($tc, "\e[48;2;"), 'truecolor stream emits 48;2 SGR');
$assert(str_starts_with($tc, "\e[?25l") && str_ends_with($tc, "\e[?25h"), 'stream wrapped in cursor hide/show');
$frames = explode("\e[" . DupBanner::ROWS . "A\r", $tc);
$assert(count($frames) === DupBanner::TICKS, 'stream splits into ' . DupBanner::TICKS . ' frames');

// -------------------------------------------------- 256 fallback: valid SGR
$seq = $b->play256();
$assert(!str_contains($seq, "\e[38;2;") && !str_contains($seq, "\e[48;2;"), '256 stream contains no 38;2/48;2 codes');
$valid = true;
preg_match_all('/\e\[([0-9;?]*)([A-Za-z])/', $seq, $ms, PREG_SET_ORDER);
foreach ($ms as $m) {
    [, $params, $cmd] = $m;
    if ($cmd === 'l' || $cmd === 'h') {
        if ($params !== '?25') { $valid = false; break; }
        continue;
    }
    if ($cmd === 'A') {
        if ($params !== (string) DupBanner::ROWS) { $valid = false; break; }
        continue;
    }
    if ($cmd !== 'm') { $valid = false; break; }
    if ($params === '0') continue;
    if (preg_match('/^(38|48);5;(\d{1,3})$/', $params, $mm)) {
        if ((int) $mm[2] > 255) { $valid = false; break; }
        continue;
    }
    $valid = false;
    break;
}
$assert($valid, 'every SGR in the 256-color stream is valid (38;5/48;5 <= 255, reset, cursor)');

// -------------------------------------------------- palette on final frame
$finalFrame = $b->frame(DupBanner::FINAL_TICK);
foreach ([
    "\e[48;2;17;24;39m" => 'chip body #111827',
    "\e[38;2;167;243;208m" => 'silkscreen wordmark #A7F3D0',
    "\e[48;2;11;61;46m" => 'board background #0B3D2E',
    "\e[38;2;250;204;21m" => 'call-site pads #FACC15',
    "\e[38;2;217;119;6m" => 'copper traces #D97706',
] as $needle => $desc) {
    $assert(str_contains($finalFrame, $needle), 'final frame contains ' . $desc);
}
$assert(str_contains($b->frame(5), "\e[38;2;253;186;116m"), 'tick 5 shows truecolor pulse heads #FDBA74');
$assert(str_contains($b->frame(18), "\e[38;2;251;191;36m"), 'tick 18 shows gold identity outlines #FBBF24');

if ($fails) {
    fwrite(STDERR, count($fails) . " gate(s) FAILED\n");
    exit(1);
}
echo "ALL GATES PASS\n";
