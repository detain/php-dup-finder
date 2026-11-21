<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud gate for the DupBanner deliverable.
 * Checks determinism, size caps, fallback chain, timing budget, SGR validity.
 *
 *   php verify.php
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Reporting\DupBanner;

$fail = 0;
$check = static function (bool $ok, string $what, string $detail = '') use (&$fail): void {
    if ($ok) {
        echo "PASS  $what\n";
    } else {
        echo "FAIL  $what" . ($detail !== '' ? " — $detail" : '') . "\n";
        $fail++;
    }
};

$banner = new DupBanner();
$last = DupBanner::LAST_TICK;

// ---------------------------------------------------------------- in-process determinism
$check($banner->frame(0) === $banner->frame(0), 'frame(0) byte-identical when called twice');
$check($banner->finalFrame() === $banner->frame($last), 'final frame byte-identical');
$check($banner->frame(0) !== $banner->frame(1), 'frame(1) differs from frame(0) (real animation)');
$check($banner->frameAtDepth(0, DupBanner::DEPTH_256) === $banner->frameAtDepth(0, DupBanner::DEPTH_256), '256 frame(0) deterministic');

// ---------------------------------------------------------------- cross-process determinism
foreach ([['frame(0)', 0], ['final frame', $last]] as [$label, $tick]) {
    $out1 = (string) shell_exec(sprintf('php -r %s 2>/dev/null', escapeshellarg(
        'require "' . __DIR__ . '/DupBanner.php";'
        . '$b = new Phpdup\\Reporting\\DupBanner();'
        . "echo \$b->frameAtDepth($tick, Phpdup\\Reporting\\DupBanner::DEPTH_TRUECOLOR);"
    )));
    $out2 = (string) shell_exec(sprintf('php -r %s 2>/dev/null', escapeshellarg(
        'require "' . __DIR__ . '/DupBanner.php";'
        . '$b = new Phpdup\\Reporting\\DupBanner();'
        . "echo \$b->frameAtDepth($tick, Phpdup\\Reporting\\DupBanner::DEPTH_TRUECOLOR);"
    )));
    $inproc = $tick === 0 ? $banner->frame(0) : $banner->finalFrame();
    $check($out1 === $out2 && $out1 !== '' && $out1 === $inproc,
        "$label byte-identical across two separate php invocations (and matches in-process)");
}

// ---------------------------------------------------------------- size caps
$strip = static fn (string $s): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\r/', '', $s);
$maxW = 0;
$maxH = 0;
$ragged = 0;
foreach (range(0, $last) as $t) {
    $lines = explode("\n", rtrim($strip($banner->frameAtDepth($t, DupBanner::DEPTH_TRUECOLOR)), "\n"));
    $w = (int) max(array_map(static fn (string $l): int => mb_strwidth($l, 'UTF-8'), $lines));
    $maxW = max($maxW, $w);
    $maxH = max($maxH, count($lines));
    foreach ($lines as $l) {
        if (mb_strwidth($l, 'UTF-8') !== $w) {
            $ragged++;
        }
    }
}
$check($maxH <= 7, "every tick frame <= 7 rows (max seen $maxH)");
$check($maxW <= 56, "every tick frame <= 56 cols (max seen $maxW) → no overflow at 80");
$check($maxW === 56, 'frames paint the full 56-col plate width');
$check($ragged === 0, 'rows share one visible width (clean cursor-up repaint, no bleed)');

// ---------------------------------------------------------------- timing budget
// derived from the OBSERVED stream (count($parts) frames), not the constants
$frameCount = count(explode("\e[" . DupBanner::ROWS . "A\r", $banner->stream()));
$check($frameCount <= 45, "tick count <= 45 (stream carries $frameCount frames)");
$check($frameCount * DupBanner::TICK_USEC <= 1_500_000,
    sprintf('one-shot playback %.0f ms <= 1500 ms', $frameCount * DupBanner::TICK_USEC / 1000));

// ---------------------------------------------------------------- truecolor checks
$tc = $banner->frame(20);
$check(str_contains($tc, '38;2;') && str_contains($tc, '48;2;'), 'frame carries 24-bit fg + bg SGR');
$check(!preg_match('/\e\[[0-9;]*3[48];5;/', $tc), 'truecolor frame has no 256-colour codes');
$check((bool) preg_match('/38;2;203;213;225/', $banner->frame(0)), 'graphite #CBD5E1 present');
$check(substr_count($tc, "\e[0m\n") === 7, 'every row ends with a full SGR reset');

// ---------------------------------------------------------------- xterm256 fallback
$ok256 = true;
$badSeq = '';
foreach (range(0, $last) as $t) {
    $f = $banner->frameAtDepth($t, DupBanner::DEPTH_256);
    if (str_contains($f, ';2;')) {
        $ok256 = false;
        $badSeq = 'truecolor in 256 frame';
        break;
    }
    if (preg_match_all('/\e\[([0-9;]*)m/', $f, $mm)) {
        foreach ($mm[1] as $seq) {
            $params = array_map('intval', explode(';', $seq));
            for ($i = 0; $i < count($params); $i++) {
                $v = $params[$i];
                if ($v === 38 || $v === 48) {
                    $mode = $params[$i + 1] ?? -1;
                    $idx = $params[$i + 2] ?? -1;
                    if ($mode !== 5 || $idx < 0 || $idx > 255) {
                        $ok256 = false;
                        $badSeq = $seq;
                    }
                    $i += 2;
                } elseif ($v !== 0 && $v !== 39 && $v !== 49) {
                    $ok256 = false;
                    $badSeq = $seq;
                }
            }
        }
    }
}
$check($ok256, 'all 45 xterm256 frames use only valid 38;5;N / 48;5;N / reset SGR', $badSeq);

// ---------------------------------------------------------------- plain fallback
$okPlain = true;
$nonAscii = false;
foreach ([0, 15, 30, $last] as $t) {
    $p = $banner->frameAtDepth($t, DupBanner::DEPTH_PLAIN);
    if (str_contains($p, "\e")) {
        $okPlain = false;
    }
    if (preg_match('/[^\x00-\x7F]/', $p) === 1) {
        $nonAscii = true;
    }
}
$check($okPlain, 'plain frames contain ZERO escape codes');
$check(!$nonAscii, 'plain frames are pure ASCII');
$check($banner->frameAtDepth(0, DupBanner::DEPTH_PLAIN) === $banner->frameAtDepth($last, DupBanner::DEPTH_PLAIN),
    'plain frame is static (identical at tick 0 and final)');

// ---------------------------------------------------------------- stream / hold behaviour
$stream = $banner->stream();
$parts = explode("\e[" . DupBanner::ROWS . "A\r", $stream);
$check(count($parts) === DupBanner::TICK_COUNT, 'stream = exactly TICK_COUNT cursor-joined frames');
$check(str_starts_with($stream, "\e[?25l"), 'stream hides cursor at start');
$check(str_ends_with($stream, "\e[?25h"), 'stream restores cursor at end (one-shot, no loop)');
$check(str_contains((string) end($parts), $banner->finalFrame()), 'stream ends holding the final frame');
$check($banner->stream(DupBanner::DEPTH_PLAIN) === $banner->plain(), 'plain stream == static plain text');

// ---------------------------------------------------------------- choreography (hole states)
$cells = static function (string $f, string $needle): int {
    return substr_count(preg_replace('/\e\[[0-9;]*m/', '', $f) ?? '', $needle);
};
$check($cells($banner->frame(0), '▒') === 8 && $cells($banner->frame(0), '◉') === 0, 'tick 0: all 4 holes cycling (8 ▒ cells, 0 locked)');
$check($cells($banner->frame(29), '▒') === 8 && $cells($banner->frame(30), '◉') === 2, 'cascade: hole 0 locks at tick 30 (2 ◉ cells)');
$check($cells($banner->frame(33), '◉') === 2 && $cells($banner->frame(34), '◉') === 4, 'cascade staggered: hole 1 at tick 34');
$check($cells($banner->frame(41), '◉') === 6 && $cells($banner->frame(42), '◉') === 8, 'cascade complete by tick 42');
$check($cells($banner->finalFrame(), '◉') === 8 && $cells($banner->finalFrame(), '▒') === 0, 'final frame: all holes locked');
$check($cells($banner->frame(2), '▒') === 8 && $cells($banner->frame(6), '▒') === 8, 'cycling visible across stride ticks');
// at tick 10 every hole shows a DIFFERENT type concurrently → all four words on screen at once
$f10 = $banner->frame(10);
$allWords = true;
foreach (['int', 'string', 'array', 'mixed'] as $w) {
    if (!str_contains($f10, $w)) {
        $allWords = false;
    }
}
$check($allWords, 'tick 10: all four typed slots show concurrently (staggered phases)');
$words = ['int', 'string', 'array', 'mixed'];
$seen = 0;
foreach ($words as $w) {
    foreach (range(0, $last) as $t) {
        if (str_contains($banner->frame($t), $w)) {
            $seen++;
            break;
        }
    }
}
$check($seen === 4, 'all four placeholder words cycle through the slots');

// ---------------------------------------------------------------- no clock / no unseeded rand in the source
$src = (string) file_get_contents(__DIR__ . '/DupBanner.php');
$check(preg_match('/\b(microtime|hrtime|date|gmdate|localtime|uniqid|srand|mt_srand|rand|mt_rand|random_int|shuffle|array_rand)\s*\(/', $src) === 0,
    'source contains no clock or unseeded randomness primitives');
$check(preg_match_all('/\busleep\s*\(/', $src) === 1, 'single usleep — inside play() pacing only');
$check(preg_match('/function play.*usleep/s', $src) === 1, 'the one usleep belongs to play()');

echo $fail === 0 ? "\nALL CHECKS PASS\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
