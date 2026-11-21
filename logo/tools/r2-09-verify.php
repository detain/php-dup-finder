<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud constraint gate for the Diff Fold logo.
 *
 *   php verify.php            run every check
 *   php verify.php hash N M   print md5 of frame(N) in mode M (cross-check)
 *
 * Checks: pure determinism (same process + across separate php runs for
 * tick 0 and the hold tick), <=7 rows x <=56 cols on the final frame, zero
 * escape codes in the plain fallback, <=45 ticks with the full stream,
 * xterm-256 fallback emitting only valid SGR sequences, truecolor stream
 * really using 38;2/48;2, and the 1.5 s hard playback budget.
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Logo\DupBanner;
use Phpdup\Logo\DupColorMode;

function fail(string $label): never
{
    fwrite(STDERR, "FAIL: $label\n");
    exit(1);
}

function ok(string $label): void
{
    echo "ok   $label\n";
}

function modeOf(string $name): DupColorMode
{
    return DupColorMode::from($name);
}

/** @return list<string> every escape sequence (SGR/cursor) found */
function escapesIn(string $s): array
{
    preg_match_all('/\e\[[0-9;?]*[A-Za-z]?/', $s, $m);
    return $m[0];
}

function stripEscapes(string $s): string
{
    return preg_replace('/\e\[[0-9;?]*[A-Za-z]?/', '', $s) ?? $s;
}

// ------------------------------------------------------------------ hash mode
if (($argv[1] ?? '') === 'hash') {
    echo md5(DupBanner::frame((int) $argv[2], modeOf($argv[3]))), "\n";
    exit(0);
}

$php = PHP_BINARY;
$me = escapeshellarg(__DIR__ . '/verify.php');

// 1. purity: every tick byte-identical when computed twice
for ($tick = 0; $tick < DupBanner::TICKS; $tick++) {
    foreach (['truecolor', 'xterm256', 'plain'] as $modeName) {
        if (DupBanner::frame($tick, modeOf($modeName)) !== DupBanner::frame($tick, modeOf($modeName))) {
            fail("frame($tick, $modeName) not byte-identical within one process");
        }
    }
}
ok(sprintf('purity: all %d ticks x 3 modes byte-identical twice', DupBanner::TICKS));

// 2. cross-invocation determinism for tick 0 and the hold tick
foreach ([0, DupBanner::finalTick()] as $tick) {
    foreach (['truecolor', 'xterm256', 'plain'] as $modeName) {
        $inProc = md5(DupBanner::frame($tick, modeOf($modeName)));
        $a = trim((string) shell_exec("exec $php $me hash $tick $modeName < /dev/null"));
        $b = trim((string) shell_exec("exec $php $me hash $tick $modeName < /dev/null"));
        if ($a === '' || $a !== $b || $a !== $inProc) {
            fail("frame($tick, $modeName) differs across separate php invocations ($inProc vs $a vs $b)");
        }
    }
}
ok('determinism: tick 0 + hold frame byte-identical across separate php runs');

// 3. final frame geometry: <=7 rows, <=56 visible cols, exactly 7 rows
$final = DupBanner::finalFrame();
$rows = explode("\n", stripEscapes($final));
if (count($rows) !== DupBanner::ROWS) {
    fail('final frame has ' . count($rows) . ' rows, expected ' . DupBanner::ROWS);
}
foreach ($rows as $i => $row) {
    if (mb_strwidth($row, 'UTF-8') > DupBanner::COLS) {
        fail("final row $i is " . mb_strwidth($row, 'UTF-8') . ' cols wide (> ' . DupBanner::COLS . ')');
    }
}
ok(sprintf('final frame: %d rows x max %d cols', count($rows), max(array_map(static fn(string $r): int => mb_strwidth($r, 'UTF-8'), $rows))));

// 4. plain fallback: zero escape codes anywhere
$plainStream = DupBanner::play(DupColorMode::Plain);
if (str_contains($plainStream, "\e")) {
    fail('plain fallback contains an escape code');
}
if (str_contains(DupBanner::staticText(), "\e")) {
    fail('staticText contains an escape code');
}
ok('plain fallback: zero escape bytes in stream + static text');

// 5. budget: <=45 ticks, <=1.5 s playback, hold frame is the last frame
// (read via reflection so this gate tests the LIVE class values and PHPStan
// cannot pre-fold the comparison)
$readConst = static function (string $name): int {
    $c = new ReflectionClassConstant(DupBanner::class, $name);
    $value = $c->getValue();
    return is_int($value) ? $value : 0;
};
if ($readConst('TICKS') > 45) {
    fail('TICKS > 45');
}
if ($readConst('TICKS') / $readConst('FPS') > 1.5 + 1e-9) {
    fail('playback exceeds 1.5 s');
}
if ($readConst('FPS') > 30) {
    fail('FPS exceeds 30');
}
foreach (['truecolor', 'xterm256'] as $modeName) {
    $stream = DupBanner::play(modeOf($modeName));
    $frames = preg_split('/\e\[\d+A\r/', preg_replace('/\e\[\?25[hl]/', '', $stream) ?? $stream) ?: [];
    if (count($frames) !== DupBanner::TICKS) {
        fail("$modeName stream holds " . count($frames) . ' frames, expected ' . DupBanner::TICKS);
    }
    if (strpos((string) end($frames), DupBanner::finalFrame(modeOf($modeName))) !== 0) {
        fail("$modeName stream does not end on the hold frame");
    }
    $secs = count($frames) / DupBanner::FPS;
    ok(sprintf('%s stream: %d frames = %.3fs (<=1.5s), ends on hold', $modeName, count($frames), $secs));
}

// 6. xterm-256 fallback: only valid SGR sequences (no 38;2 leaks, indices 0..255)
$valid = 0;
foreach (escapesIn(str_replace(["\e[?25l", "\e[?25h"], '', DupBanner::play(DupColorMode::Xterm256))) as $esc) {
    if (preg_match('/\A\e\[6A\z/', $esc)) {
        continue; // framing cursor-up
    }
    if (preg_match('/\A\e\[(0|1|22|39|49)m\z/', $esc) === 1) {
        $valid++;
        continue;
    }
    $mm = [];
    if (preg_match('/\A\e\[([34]8);5;(\d{1,3})m\z/', $esc, $mm) !== 1) {
        fail('256 stream emitted invalid escape: ' . addcslashes($esc, "\x00..\x1f"));
    }
    if ((int) $mm[2] > 255) {
        fail('256 stream colour index out of range: ' . $mm[2]);
    }
    $valid++;
}
if (str_contains(DupBanner::play(DupColorMode::Xterm256), ';2;')) {
    fail('256 fallback leaked a truecolor sequence');
}
ok("xterm-256 fallback: $valid SGR sequences, all valid");

// 7. truecolor: actually uses 24-bit sequences, including background tints
$tc = DupBanner::play(DupColorMode::Truecolor);
if (substr_count($tc, "\e[38;2;") < 10 || substr_count($tc, "\e[48;2;") < 10) {
    fail('truecolor stream lacks 24-bit fg/bg sequences');
}
ok(sprintf('truecolor stream: %d fg + %d bg 24-bit sequences', substr_count($tc, "\e[38;2;"), substr_count($tc, "\e[48;2;")));

// 8. deliverable files exist and match the class byte-for-byte
foreach (['logo.ansi', 'logo-static.ansi', 'logo-static.txt', 'logo.gif'] as $file) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        fail("missing deliverable $file (run build.sh first)");
    }
}
if (file_get_contents(__DIR__ . '/logo.ansi') !== DupBanner::play(DupColorMode::Truecolor)) {
    fail('logo.ansi on disk differs from DupBanner::play(truecolor) — rebuild needed');
}
ok('deliverables present; logo.ansi byte-identical to class output');

echo "\nPASS: all Diff Fold constraints verified\n";
