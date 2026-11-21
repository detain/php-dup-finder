<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud constraint gate for the Five-Stage Conveyor banner.
 * Run: php verify.php   (exit 0 + ALL PASS, or first failure aborts loudly)
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Ui\DupBanner;

$asserts = 0;

function ok(bool $cond, string $what): void
{
    global $asserts;
    $asserts++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $what\n");
        exit(1);
    }
    echo "pass: $what\n";
}

/** Strip SGR + cursor sequences, keep only the printable grid. */
function strip(string $frame): string
{
    return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $frame);
}

/**
 * @return list<string>
 */
function rowsOf(string $frame): array
{
    return explode("\n", strip($frame));
}

// -- timing / size budget gates ----------------------------------------------
// driven off the REAL runtime stream (not the class constants), so these stay
// genuine checks instead of compile-time tautologies.
$stream = DupBanner::stream(DupBanner::MODE_TRUECOLOR);
$split = preg_split('/\e\[H/', $stream);
if (!is_array($split)) {
    fwrite(STDERR, "FAIL: stream does not split on cursor-home framing\n");
    exit(1);
}
$frameCount = count($split) - 1;
ok($frameCount <= 45, 'tick count <= 45 (' . $frameCount . ')');
$spanUs = $frameCount * DupBanner::FRAME_US;
ok($spanUs <= 1_500_000, 'playback <= 1.5 s (' . sprintf('%.3f s', $spanUs / 1e6) . ')');

// -- determinism: same tick twice in-process, and clamps --------------------
$f0a = DupBanner::frame(0, DupBanner::MODE_TRUECOLOR);
$f0b = DupBanner::frame(0, DupBanner::MODE_TRUECOLOR);
ok($f0a === $f0b, 'frame(0) byte-identical on repeat call');
$fLa = DupBanner::frame(DupBanner::FINAL_TICK, DupBanner::MODE_TRUECOLOR);
$fLb = DupBanner::frame(DupBanner::FINAL_TICK, DupBanner::MODE_TRUECOLOR);
ok($fLa === $fLb, 'frame(final) byte-identical on repeat call');
ok(DupBanner::frame(999) === DupBanner::frame(DupBanner::FINAL_TICK), 'frame(t > final) holds final frame');
ok(DupBanner::frame(-5) === DupBanner::frame(0), 'frame(t < 0) clamps to tick 0');

// -- determinism: across separate php invocations --------------------------
$probe = 'require $argv[1]; echo hash("sha256", Phpdup\\Ui\\DupBanner::frame((int) $argv[2], Phpdup\\Ui\\DupBanner::MODE_TRUECOLOR));';
$base = ['php', '-r', $probe, escapeshellarg(__DIR__ . '/DupBanner.php')];
$hash = static function (int $tick) use ($base): string {
    $cmd = $base[0] . ' ' . $base[1] . ' ' . escapeshellarg($base[2]) . ' ' . $base[3] . ' ' . $tick;
    $h1 = trim((string) shell_exec($cmd));
    $h2 = trim((string) shell_exec($cmd));
    ok($h1 !== '' && $h1 === $h2, "cross-process sha256 stable for tick $tick");
    return $h1;
};
$h0 = $hash(0);
ok($h0 === hash('sha256', $f0a), 'cross-process tick 0 matches in-process bytes');
$hL = $hash(DupBanner::FINAL_TICK);
ok($hL === hash('sha256', $fLa), 'cross-process final tick matches in-process bytes');

// -- canvas bounds: every frame <= 7 rows, rows <= 56 display cols ----------
$overflow = [];
for ($t = 0; $t <= DupBanner::FINAL_TICK; $t++) {
    $rows = rowsOf(DupBanner::frame($t));
    if (count($rows) > DupBanner::ROWS) {
        $overflow[] = "tick $t rows " . count($rows);
    }
    foreach ($rows as $i => $r) {
        if (mb_strwidth($r, 'UTF-8') > DupBanner::COLS) {
            $overflow[] = "tick $t row $i width " . mb_strwidth($r, 'UTF-8');
        }
    }
}
ok($overflow === [], 'all 45 frames fit 7x56 with no overflow' . ($overflow ? ' (' . implode(', ', array_slice($overflow, 0, 4)) . ')' : ''));
$finalRows = rowsOf($fLa);
ok(count($finalRows) === DupBanner::ROWS && mb_strwidth($finalRows[3], 'UTF-8') === DupBanner::COLS, 'final frame is exactly 7 x 56');

// -- wordmark must actually be present on the hold frame --------------------
$flat = str_replace(' ', '', strip($fLa));
ok(str_contains($flat, 'phpdup'), 'final frame contains "phpdup" wordmark');
ok(str_contains($flat, 'Scan') && str_contains($flat, 'Preprocess') && str_contains($flat, 'Cluster') && str_contains($flat, 'Refactor') && str_contains($flat, 'Report'), 'final frame shows all five stage labels');

// -- plain fallback: zero escapes --------------------------------------------
$plain = DupBanner::plain();
ok(!str_contains($plain, "\e"), 'plain fallback contains ZERO escape codes');
ok($plain === strip(DupBanner::frame(DupBanner::FINAL_TICK, DupBanner::MODE_PLAIN)), 'plain() equals MODE_PLAIN frame rendering');
ok(DupBanner::stream(DupBanner::MODE_PLAIN) === $plain, 'stream(plain) is the single static frame');
ok(str_contains(str_replace(' ', '', $plain), 'phpdup'), 'plain fallback still reads as the logo');

// -- 256-color fallback: valid SGR only --------------------------------------
$f256 = DupBanner::frame(10, DupBanner::MODE_256);
ok(!str_contains($f256, '38;2;'), '256 mode emits no truecolor sequences');
ok(!str_contains($f256, '48;'), '256 mode emits no background colour sequences');
preg_match_all('/\e\[([0-9;]*)m/', $f256, $m);
$badSgr = [];
foreach ($m[1] as $params) {
    if ($params === '' || $params === '0' || $params === '1') {
        continue;
    }
    if (!preg_match('/^(?:1;)?38;5;(\d{1,3})$/', $params, $g)) {
        $badSgr[] = $params;
        continue;
    }
    if ((int) $g[1] > 255) {
        $badSgr[] = $params;
    }
}
ok($badSgr === [], '256 mode SGR vocabulary valid' . ($badSgr ? ' (bad: ' . implode(', ', $badSgr) . ')' : ''));
ok(str_contains($f256, '38;5;'), '256 mode actually uses 38;5;n');

// -- truecolor stream shape --------------------------------------------------
ok(str_starts_with($stream, "\e[?25l"), 'stream hides cursor up front');
ok(str_ends_with($stream, "\e[?25h"), 'stream shows cursor at the end (hold intact)');
ok($frameCount === DupBanner::TICKS, 'stream carries exactly ' . DupBanner::TICKS . ' repainted frames');
ok(str_contains($stream, '38;2;'), 'stream is truecolour');
ok(str_contains($stream, '48;2;') === false, 'stream never sets background colours (transparent canvas)');

// -- logo.ansi on disk is class-generated ------------------------------------
$ansi = file_get_contents(__DIR__ . '/logo.ansi');
ok(is_string($ansi) && $ansi === $stream, 'logo.ansi on disk is byte-identical to DupBanner::stream()');

echo "\nALL PASS ($asserts asserts)\n";
