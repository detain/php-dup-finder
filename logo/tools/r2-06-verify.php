<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud contract checks for RadarBanner (exit non-zero on
 * any violation, one PASS line per check).
 *
 * Run: php verify.php
 */

require __DIR__ . '/RadarBanner.php';

use Phpdup\Branding\RadarBanner;

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "PASS: $msg\n";
}

/** @return list<string> */
function frameRows(string $frame): array
{
    return explode("\n", rtrim($frame, "\n"));
}

function stripSgr(string $s): string
{
    return (string) preg_replace('/\e\[[0-9;]*m/', '', $s);
}

/**
 * Walk every SGR sequence, assert colour codes fit an allowed depth.
 * @param 'tc'|'256' $depth
 */
function checkSgrDepth(string $ansi, string $depth, string $label): void
{
    preg_match_all('/\e\[([0-9;]*)m/', $ansi, $m);
    $seen = [];
    foreach ($m[1] as $seq) {
        $p = $seq === '' ? [0] : array_map('intval', explode(';', $seq));
        $i = 0;
        while ($i < count($p)) {
            $v = $p[$i];
            if ($v === 0 || $v === 39 || $v === 49) {
                $i++;
                continue;
            }
            if ($v === 38 || $v === 48) {
                $kind = $p[$i + 1] ?? -1;
                $idx = ($depth === 'tc') ? 2 : 5;
                if ($kind !== $idx) {
                    fwrite(STDERR, "FAIL: $label: SGR $seq not allowed at depth $depth\n");
                    exit(1);
                }
                if ($depth === 'tc') {
                    foreach ([2, 3, 4] as $o) {
                        $c = $p[$i + $o] ?? -1;
                        if ($c < 0 || $c > 255) {
                            fwrite(STDERR, "FAIL: $label: bad tc component in SGR $seq\n");
                            exit(1);
                        }
                    }
                    $i += 5;
                } else {
                    $n = $p[$i + 2] ?? -1;
                    if ($n < 0 || $n > 255) {
                        fwrite(STDERR, "FAIL: $label: bad 256 index in SGR $seq\n");
                        exit(1);
                    }
                    $i += 3;
                }
                $seen[$depth] = true;
                continue;
            }
            fwrite(STDERR, "FAIL: $label: unexpected SGR param $v\n");
            exit(1);
        }
    }
    if (!isset($seen[$depth])) {
        fwrite(STDERR, "FAIL: $label: no $depth colour codes found\n");
        exit(1);
    }
}

$banner = new RadarBanner();
$f0 = $banner->frame(0);
$fF = $banner->finalFrame();

// ---------- determinism (within-process) ----------
check($banner->frame(0) === $f0, 'frame(0) byte-identical when called twice');
check($banner->finalFrame() === $fF, 'final frame byte-identical when called twice');

// ---------- determinism (separate php invocations) ----------
$php = escapeshellarg(PHP_BINARY);
$cls = escapeshellarg(__DIR__ . '/RadarBanner.php');
$probe = 'require $argv[1]; echo (new Phpdup\\Branding\\RadarBanner())->frame((int) $argv[2]);';
$x0 = (string) shell_exec("$php -r " . escapeshellarg($probe) . " $cls 0");
$xF = (string) shell_exec("$php -r " . escapeshellarg($probe) . " $cls " . RadarBanner::FINAL_TICK);
check($x0 !== '' && $x0 === $f0, 'frame(0) byte-reproducible across php invocations');
check($xF !== '' && $xF === $fF, 'final frame byte-reproducible across php invocations');

// ---------- size caps on the final (held) frame ----------
$rows = frameRows($fF);
check(count($rows) === 7, 'final frame is exactly 7 rows (cap 7)');
foreach ($rows as $i => $r) {
    check(str_ends_with($r, "\e[0m"), "final row $i ends with SGR reset");
}
$widths = array_map(static fn (string $r): int => mb_strwidth(stripSgr($r), 'UTF-8'), $rows);
check(count(array_unique($widths)) === 1, 'all final rows share one width');
check($widths[0] <= 56, "final width {$widths[0]} cols <= 56");
check($widths[0] <= 80, 'no horizontal overflow at 80 cols');
$f0Rows = frameRows($f0);
check(count($f0Rows) === 7, 'frame(0) is exactly 7 rows');

// ---------- animation budget ----------
$play = $banner->play();
$frameCount = count(explode("\e[" . RadarBanner::TEXT_ROWS . "A\r", $play));
check($frameCount <= 45, 'tick budget <= 45 (45 x 33.3 ms = 1.5 s @30 fps)');
check(str_starts_with($play, "\e[?25l"), 'play stream hides the cursor');
check(str_ends_with($play, $fF . "\e[?25h"), 'play stream ends on the frozen hold frame');
check($frameCount === RadarBanner::TICKS, 'play stream contains exactly 45 frames');
check($fF !== $f0, 'final frame differs from frame 0 (animation is not a no-op)');

// ---------- truecolor depth ----------
check(str_contains($fF, "\e[38;2;"), 'truecolor frame emits 38;2 foreground codes');
check(str_contains($fF, "\e[48;2;"), 'truecolor frame emits 48;2 background codes');
checkSgrDepth($play, 'tc', 'logo.ansi/play');

// ---------- 256 fallback ----------
$f256 = $banner->frameAtDepth(0, '256');
check(str_contains($f256, '38;5;'), '256 fallback emits 38;5 codes');
checkSgrDepth($banner->play('256'), '256', 'logo-256.ansi/play');

// ---------- plain fallback ----------
$plain = RadarBanner::plainFrame();
check(!str_contains($plain, "\e"), 'plain frame contains zero ESC codes');
check(trim($plain) !== '', 'plain frame is not empty');
check(mb_strwidth($plain, 'UTF-8') <= 56, 'plain frame fits the 56-col cap');
check($banner->render(false, []) === $plain . "\n", 'undecorated render() returns the plain frame');

// ---------- fallback chain ----------
check(RadarBanner::detectDepth(true, ['NO_COLOR' => '1']) === 'plain', 'NO_COLOR forces plain');
check(RadarBanner::detectDepth(true, ['COLORTERM' => 'truecolor']) === 'tc', 'COLORTERM=truecolor -> tc');
check(RadarBanner::detectDepth(true, ['COLORTERM' => '24bit']) === 'tc', 'COLORTERM=24bit -> tc');
check(RadarBanner::detectDepth(true, ['TERM' => 'xterm-256color']) === '256', 'no truecolor env -> 256');

// ---------- heat ramp (similarity story) ----------
check($banner->heat(0.0) === [0x0E, 0xA5, 0xE9], 'heat(0) = cool #0EA5E9');
check($banner->heat(1.0) === [0xEF, 0x44, 0x44], 'heat(1) = hot #EF4444');

// ---------- deliverable files are in sync with the class ----------
foreach (['logo.ansi' => $banner->play('tc'), 'logo-256.ansi' => $banner->play('256'), 'logo-still.txt' => $fF] as $file => $expect) {
    $path = __DIR__ . '/' . $file;
    check(is_file($path) && file_get_contents($path) === $expect, "$file on disk matches class output");
}

echo "\nALL CHECKS PASSED\n";
