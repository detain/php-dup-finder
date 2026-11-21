<?php

declare(strict_types=1);

/**
 * verify — fail-loud asserts for the LensBanner deliverables.
 *
 * Covers: byte-identical determinism (twice in-process, and across separate
 * php invocations for tick 0 + final), final-frame geometry (<= 7 rows x
 * <= 56 cols), plain fallback carries zero escapes, tick budget (<= 45 ticks
 * at <= 30 fps => <= 1.5 s), and 256-depth SGR validity.
 *
 *   php verify.php            # 0 on success, 1 on first failure list
 */

require __DIR__ . '/LensBanner.php';

$failures = 0;
$assert = static function (bool $ok, string $what) use (&$failures): void {
    if ($ok) {
        fwrite(STDOUT, '  ok  ' . $what . PHP_EOL);
    } else {
        $failures++;
        fwrite(STDERR, 'FAIL  ' . $what . PHP_EOL);
    }
};

$strip = static fn (string $s): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $s);

/** visible widths per line + line count of an encoded frame */
$geom = static function (string $frame) use ($strip): array {
    $lines = explode("\n", rtrim($frame, "\n"));
    $w = 0;
    foreach ($lines as $l) {
        $w = max($w, mb_strwidth($strip($l), 'UTF-8'));
    }
    return [count($lines), $w];
};

// ---- determinism -----------------------------------------------------------
$f0a = LensBanner::frame(0);
$f0b = LensBanner::frame(0);
$fLa = LensBanner::frame(LensBanner::LAST_TICK);
$fLb = LensBanner::frame(LensBanner::LAST_TICK);
$assert($f0a === $f0b, 'frame(0) byte-identical twice in-process');
$assert($fLa === $fLb, 'final frame byte-identical twice in-process');

$hash = static fn (string $s): string => hash('sha256', $s);
$proc0 = trim((string) shell_exec(
    'php -r ' . escapeshellarg('require $argv[1]; echo hash("sha256", LensBanner::frame(0));')
    . ' ' . escapeshellarg(__DIR__ . '/LensBanner.php')
));
$procF = trim((string) shell_exec(
    'php -r ' . escapeshellarg('require $argv[1]; echo hash("sha256", LensBanner::frame(LensBanner::LAST_TICK));')
    . ' ' . escapeshellarg(__DIR__ . '/LensBanner.php')
));
$assert($proc0 === $hash($f0a), 'frame(0) reproducible across separate php invocations');
$assert($procF === $hash($fLa), 'final frame reproducible across separate php invocations');

// ---- geometry --------------------------------------------------------------
[$rows, $cols] = $geom($fLa);
$assert($rows <= 7, 'final frame rows <= 7 (got ' . $rows . ')');
$assert($cols <= 56, 'final frame visible cols <= 56 (got ' . $cols . ')');
$assert($cols + 24 <= 80, 'no horizontal overflow at 80 cols (' . $cols . ' + banner margin)');

$allOk = true;
for ($t = 0; $t <= LensBanner::LAST_TICK; $t++) {
    [$r, $c] = $geom(LensBanner::frame($t));
    if ($r !== 7 || $c > 56 || !str_ends_with(LensBanner::frame($t), "\e[0m\n")) {
        $allOk = false;
    }
}
$assert($allOk, 'every tick: exactly 7 rows, <=56 cols, lines end \\e[0m');

// ---- plain fallback --------------------------------------------------------
$plain = LensBanner::plain();
$assert(!str_contains($plain, "\e"), 'plain fallback contains zero escape codes');
[$pr, $pc] = $geom($plain);
$assert($pr <= 7 && $pc <= 56, 'plain fallback fits 7x56 (got ' . $pr . 'x' . $pc . ')');

// ---- timing budget ---------------------------------------------------------
// Read via reflection so the checks stay honest runtime assertions even when
// the limits are class constants (and so they fail loudly if the class drifts).
$rc = new ReflectionClass(LensBanner::class);
$ticks = (int) $rc->getConstant('TICKS');
$tickUs = (int) $rc->getConstant('TICK_US');
$lastTick = (int) $rc->getConstant('LAST_TICK');
$assert($ticks <= 45, 'tick count <= 45 (got ' . $ticks . ')');
$assert($tickUs >= 33333, 'frame pacing <= 30 fps (' . $tickUs . ' us)');
$assert($lastTick * $tickUs <= 1_500_000,
    'playback budget <= 1.5 s (' . sprintf('%.3f', $lastTick * $tickUs / 1e6) . ' s)');

// ---- depth chains ----------------------------------------------------------
$tc = LensBanner::frameAt(0, LensBanner::DEPTH_TRUECOLOR);
$assert(str_contains($tc, "\e[38;2;"), 'truecolor frame emits 38;2 SGR');
$tcf = LensBanner::finalFrame(LensBanner::DEPTH_TRUECOLOR);
$assert(str_contains($tcf, "\e[38;2;"), 'truecolor final frame emits 38;2 SGR');

foreach ([LensBanner::frameAt(0, LensBanner::DEPTH_256), LensBanner::finalFrame(LensBanner::DEPTH_256)] as $i => $n) {
    $assert(!str_contains($n, '38;2;') && !str_contains($n, '48;2;'), '256 frame[' . $i . '] has no truecolor SGR');
    preg_match_all('/\e\[([0-9;]*)m/', $n, $m);
    $valid = true;
    foreach ($m[1] as $seq) {
        $parts = $seq === '' ? [0] : array_map('intval', explode(';', $seq));
        $nParts = count($parts);
        for ($p = 0; $p < $nParts; $p++) {
            $code = $parts[$p];
            if ($code === 38 || $code === 48) {
                if ($p + 2 >= $nParts || $parts[$p + 1] !== 5 || $parts[$p + 2] < 0 || $parts[$p + 2] > 255) {
                    $valid = false;
                }
                $p += 2;
            } elseif ($code !== 0) {
                $valid = false;
            }
        }
    }
    $assert($valid, '256 frame[' . $i . '] emits valid 38;5/48;5 SGR only');
    [$nr, $nc] = $geom($n);
    $assert($nr <= 7 && $nc <= 56, '256 frame[' . $i . '] fits geometry');
}

// ---- hold semantics of the raw stream ---------------------------------------
$stream = LensBanner::render();
$assert(str_starts_with($stream, "\e[?25l"), 'render hides cursor up-front');
$assert(str_ends_with($stream, "\e[?25h"), 'render shows cursor after hold');
$tail = explode("\e[" . LensBanner::ROWS . 'A' . "\r", $stream);
$assert(str_contains((string) end($tail), "\e[38;2;"), 'stream ends holding the final frame');
$finalPlain = $strip((string) end($tail));
$assert(str_contains($finalPlain, '█'), 'held frame contains the block-glyph wordmark');

// ---- fail-fast on bad ticks -------------------------------------------------
$threw = false;
try {
    LensBanner::frame(-1);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
$assert($threw, 'frame(-1) throws (fail fast)');

fwrite(STDOUT, $failures === 0 ? "ALL CHECKS PASSED\n" : $failures . " FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
