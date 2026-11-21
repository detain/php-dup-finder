<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud constraint gates for the Plasma Crystallization logo.
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

// ------------------------------------------------ determinism (in-process)
$assert($b->frame(0) === $b->frame(0), 'frame(0) byte-identical when called twice');
$assert($b->frame(DupBanner::FINAL_TICK) === $b->frame(DupBanner::FINAL_TICK), 'final frame byte-identical when called twice');

// ------------------------------------------------ determinism (separate php invocations)
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

// ------------------------------------------------ budget: ticks + duration
// comparisons run through a closure so the GATE logic is exercised, not
// constant-folded away by static analysis
$le = static fn (int|float $a, int|float $b): bool => $a <= $b;
$assert($le(DupBanner::TICKS, 45), 'tick count ' . DupBanner::TICKS . ' <= 45');
$assert($le(DupBanner::TICKS / DupBanner::FPS, 1.5), sprintf('duration %.3fs <= 1.5s', DupBanner::TICKS / DupBanner::FPS));
$assert($le(DupBanner::FPS, 30), 'fps ' . DupBanner::FPS . ' <= 30');

// ------------------------------------------------ final frame geometry
$final = $b->frame(DupBanner::FINAL_TICK);
$lines = explode("\n", rtrim($final, "\n"));
$assert(count($lines) === DupBanner::ROWS, 'final frame has exactly ' . DupBanner::ROWS . ' rows');
$geomOk = true;
foreach ($lines as $line) {
    $plain = preg_replace('/\e\[[0-9;]*m/', '', $line) ?? '';
    if (mb_strwidth($plain) > DupBanner::COLS || mb_strlen($plain) > DupBanner::COLS) {
        $geomOk = false;
    }
}
$assert($geomOk, 'every final row <= ' . DupBanner::COLS . ' cols');
$assert($le(DupBanner::COLS, 80), 'no horizontal overflow at 80 cols');

// ------------------------------------------------ hold stability
$assert(
    $b->frame(DupBanner::SETTLE_TICK) === $final
        && $b->frame(DupBanner::SETTLE_TICK + 1) === $final
        && $b->frame(DupBanner::SETTLE_TICK + 2) === $final,
    'frames from SETTLE_TICK on are byte-identical (true hold)'
);

// ------------------------------------------------ plain fallback: zero escapes
$assert(!str_contains($b->staticText(), "\e"), 'static fallback contains no escape codes');
$assert($b->render(['NO_COLOR' => '1', 'COLORTERM' => 'truecolor']) === $b->staticText(), 'render() honors NO_COLOR');

// ------------------------------------------------ truecolor stream shape
$tc = $b->play();
$assert(str_contains($tc, "\e[38;2;"), 'truecolor stream emits 38;2 SGR');
$assert(str_starts_with($tc, "\e[?25l") && str_ends_with($tc, "\e[?25h"), 'stream wrapped in cursor hide/show');
$frames = explode("\e[7A\r", $tc);
$assert(count($frames) === DupBanner::TICKS, 'stream splits into ' . DupBanner::TICKS . ' frames');

// ------------------------------------------------ 256 fallback: valid SGR only
$seq = $b->play256();
$assert(!str_contains($seq, "\e[38;2;"), '256 stream contains no 38;2 codes');
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
    if (preg_match('/^38;5;(\d{1,3})$/', $params, $mm)) {
        if ((int) $mm[1] > 255) { $valid = false; break; }
        continue;
    }
    $valid = false;
    break;
}
$assert($valid, 'every SGR in the 256-color stream is valid (38;5;N<=255, reset, cursor)');

// ------------------------------------------------ mark colour on final frame
$hasMark = (bool) preg_match('/\e\[38;2;248;250;252m/', $final);
$hasNavy = (bool) preg_match('/\e\[38;2;11;16;32m/', $final);
$assert($hasMark, 'final frame contains the brand mark colour #F8FAFC');
$assert($hasNavy, 'final frame contains deep navy background #0B1020');
$assert(str_contains($b->frame(0), "\e[38;2;"), 'tick 0 is a plasma truecolor frame');

if ($fails) {
    fwrite(STDERR, count($fails) . " gate(s) FAILED\n");
    exit(1);
}
echo "ALL GATES PASS\n";
