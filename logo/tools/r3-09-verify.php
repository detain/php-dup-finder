<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud contract checks for the Debt Skyline banner.
 *
 *   php verify.php
 *
 * Checks (each prints PASS/FAIL, any FAIL exits 1):
 *  1. cross-process byte determinism of frame(0) and the final frame
 *  2. final frame fits <= 7 rows x <= 56 cols, whole canvas <= 80 cols
 *  3. plain fallback (undecorated / NO_COLOR) contains ZERO escapes
 *  4. <= 45 ticks and <= 1.5 s budget and <= 30 fps (TICK_MS >= 34)
 *  5. the 256-colour path emits only valid SGR (38;5;n / 48;5;n, n 0..255)
 *  6. final frame IS the letter-shape skyline: ink cells match the PHPDUP
 *     mask exactly, warm windows present, moon present, gate line intact,
 *     and NOT A SINGLE red violation cell remains above the gate
 *  7. frame(0) is empty sky (nothing built yet)
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Branding\DupBanner;

$fails = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fails): void {
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail === '' ? '' : " — $detail");
    if (!$ok) {
        $fails++;
    }
};

// -- 1. cross-process determinism ----------------------------------------------
$mk = static fn (int $t): string => (new DupBanner('tc'))->frame($t);
$tick0 = $mk(0);
$final = $mk(DupBanner::TICKS - 1);
$probe = <<<'PHP'
<?php
require __DIR__.'/DupBanner.php';
use Phpdup\Branding\DupBanner;
echo md5((new DupBanner('tc'))->frame(0)), "\n", md5((new DupBanner('tc'))->frame(DupBanner::TICKS - 1)), "\n";
PHP;
file_put_contents(__DIR__ . '/.determinism-probe.php', $probe);
$other = shell_exec('php ' . escapeshellarg(__DIR__ . '/.determinism-probe.php') . ' 2>&1');
unlink(__DIR__ . '/.determinism-probe.php');
$lines = explode("\n", trim((string) $other));
$check(
    'cross-process byte determinism (tick 0 + final)',
    count($lines) >= 2 && $lines[0] === md5($tick0) && $lines[1] === md5($final),
    isset($lines[0], $lines[1]) ? "child md5 {$lines[0]}/{$lines[1]}" : "child said: $other",
);

// -- 2. final frame geometry ---------------------------------------------------
$strip = static fn (string $s): string => preg_replace('/\e\[[0-9;?]*[A-Za-z]/u', '', $s) ?? '';
$rows = explode("\n", rtrim($strip($final), "\n"));
$w = 0;
foreach ($rows as $r) {
    $w = max($w, mb_strwidth($r, 'UTF-8'));
}
$check('final frame <= 7 rows', count($rows) <= 7, count($rows) . ' rows');
$check('final frame <= 56 cols', $w <= 56, "$w cols");

// -- 3. plain fallback has zero escapes ----------------------------------------
$plain = (new DupBanner('plain'))->render();
$check('plain fallback contains no \e', !str_contains($plain, "\e"));
putenv('NO_COLOR=1');
$check(
    'NO_COLOR env resolves to plain depth',
    DupBanner::fromEnv()->depth() === 'plain',
    DupBanner::fromEnv()->depth(),
);
putenv('NO_COLOR');

// -- 4. animation budget --------------------------------------------------------
$check(
    'ticks <= 45 and duration <= 1500 ms and <= 30 fps',
    DupBanner::TICKS <= 45
        && DupBanner::TICKS * DupBanner::TICK_MS <= 1500
        && DupBanner::TICK_MS >= 34,
    sprintf('%d ticks x %d ms = %d ms', DupBanner::TICKS, DupBanner::TICK_MS, DupBanner::TICKS * DupBanner::TICK_MS),
);

// -- 5. 256-colour path valid SGR ----------------------------------------------
$b256 = (new DupBanner('256'))->play();
$badSeq = 0;
preg_match_all("/\e\[([0-9;]*)m/", $b256, $mm);
foreach ($mm[1] as $params) {
    if ($params === '0' || $params === '39' || $params === '49') {
        continue;
    }
    if (preg_match('/^(38|48);5;(\d{1,3})$/', $params, $g) && (int) $g[2] <= 255) {
        continue;
    }
    $badSeq++;
}
$check('256 fallback emits only 38;5/48;5/0/39/49 SGR', $badSeq === 0, "$badSeq bad sequences");

// -- 6. final frame is the letter skyline ---------------------------------------
/** Decode the truecolor final frame back into a 14x48 sub-row pixel buffer. */
$decode = static function (string $frame): array {
    $sub = array_fill(0, DupBanner::ROWS * 2, array_fill(0, DupBanner::COLS, null));
    $rows = explode("\n", rtrim($frame, "\n"));
    foreach ($rows as $r => $line) {
        $fg = null;
        $bg = null;
        $x = 0;
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m)) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0) {
                        $fg = $bg = null;
                    } elseif (($v === 38 || $v === 48) && ($p[$i + 1] ?? 0) === 2) {
                        $c = [$p[$i + 2], $p[$i + 3], $p[$i + 4]];
                        $i += 4;
                        $v === 38 ? $fg = $c : $bg = $c;
                    }
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                if ($g === '█') {
                    $sub[$r * 2][$x] = $fg;
                    $sub[$r * 2 + 1][$x] = $fg;
                } elseif ($g === '▀') {
                    $sub[$r * 2][$x] = $fg;
                    $sub[$r * 2 + 1][$x] = $bg;
                } elseif ($g === '▄') {
                    $sub[$r * 2][$x] = $bg;
                    $sub[$r * 2 + 1][$x] = $fg;
                }
                $x++;
            }
        }
    }
    return $sub;
};
$pix = $decode($final);

// Expected letter mask (mirrors the class font — the concept's letters).
$font = [
    'p' => ['1110', '1001', '1110', '1000', '1000'],
    'h' => ['1000', '1110', '1001', '1001', '1001'],
    'd' => ['0001', '0111', '1001', '1001', '0111'],
    'u' => ['0000', '1001', '1001', '1001', '0110'],
];
$word = ['p', 'h', 'p', 'd', 'u', 'p'];
$wordX = 9;
$near = static fn (?array $c, array $rgb): bool => $c !== null
    && abs($c[0] - $rgb[0]) <= 12 && abs($c[1] - $rgb[1]) <= 12 && abs($c[2] - $rgb[2]) <= 12;
// expected dusk sky colour at a sub-row (mirrors the renderer's formula)
$skyAt = static fn (int $s): array => [
    (int) round(30 + (249 - 30) * (($s / 13) ** 1.6)),
    (int) round(27 + (115 - 27) * (($s / 13) ** 1.6)),
    (int) round(75 + (22 - 75) * (($s / 13) ** 1.6)),
];
$isSky = static fn (?array $c, int $s): bool => $c !== null && $near($c, $skyAt($s));
// a pixel belongs to the moon if it has been dragged toward #FEF3C7
$isMoon = static function (?array $c, int $s) use ($skyAt): bool {
    if ($c === null) {
        return false;
    }
    $sky = $skyAt($s);
    $dm = ($c[0] - 254) ** 2 + ($c[1] - 243) ** 2 + ($c[2] - 199) ** 2;
    $ds = ($c[0] - $sky[0]) ** 2 + ($c[1] - $sky[1]) ** 2 + ($c[2] - $sky[2]) ** 2;
    return $dm < $ds;
};

$wrongShape = 0;
$windows = 0;
$redTop = 0;
for ($s = 0; $s < DupBanner::ROWS * 2; $s++) {
    for ($x = 0; $x < DupBanner::COLS; $x++) {
        $c = $pix[$s][$x];
        if ($c === null) {
            $wrongShape++;
            continue;
        }
        $wx = $x - $wordX;
        $ci = $wx % 5;
        $ink = false;
        if ($s >= 4 && $wx >= 0 && $wx < 29 && $ci >= 0 && $ci < 4) {
            $li = intdiv($wx, 5);
            $r = max(0, min(4, intdiv($s - 4, 2)));
            $ink = $font[$word[$li]][$r][$ci] === '1';
        }
        if ($ink) {
            // every stroke cell must be tower material, never plain sky
            if ($isSky($c, $s)) {
                $wrongShape++;
            }
            if ($near($c, [253, 230, 138])) {
                $windows++;
            }
        } else {
            // sky must be clean except the gate row, moon and its halo
            $allowed = $s === 3
                || ($s < 2 && $x >= 42 && ($isMoon($c, $s) || $isSky($c, $s)));
            if (!$allowed && !$isSky($c, $s)) {
                $wrongShape++; // leftover tower where the sky should be
            }
            if ($near($c, [239, 68, 68]) && $s !== 3) {
                $redTop++; // violation flash residue (gate line at s=3 is allowed)
            }
        }
    }
}
$check('final frame = exact PHPDUP skyline silhouette', $wrongShape === 0, "$wrongShape mismatched cells");
$check('warm windows glow across the wordmark', $windows >= 36, "$windows window cells");
$moon = 0;
foreach ([0, 1] as $s) {
    for ($x = 40; $x < 48; $x++) {
        if ($isMoon($pix[$s][$x], $s)) {
            $moon++;
        }
    }
}
$check('moon appeared top-right', $moon >= 4, "$moon moon cells");
$gate = 0;
for ($x = 0; $x < DupBanner::COLS; $x++) {
    if ($pix[3][$x] !== null && $near($pix[3][$x], [239, 68, 68])) {
        $gate++;
    }
}
$check('dashed red baseline (CI gate) persists in the hold', $gate >= 20 && $gate <= 26, "$gate gate cells");
$check('no red violation flash above the gate in the final frame', $redTop === 0, "$redTop cells");

// -- 7. frame 0 is a bare skyline zone -------------------------------------------
// At tick 0 the towers have only just left the ground line: nothing may exist
// in the sky rows above the wordmark zone (sub-rows 0..3) and no gate/violation
// red may be present yet (the baseline is drawn from tick 9).
$p0 = $decode((new DupBanner('tc'))->frame(0));
$built = 0;
$red = 0;
foreach ($p0 as $s => $row) {
    foreach ($row as $x => $c) {
        if ($c === null) {
            continue;
        }
        if ($s < 4 && !$isSky($c, $s) && !$isMoon($c, $s)) {
            $built++;
        }
        if ($near($c, [239, 68, 68])) {
            $red++;
        }
    }
}
$check('frame 0 has a clear sky zone and no gate yet', $built === 0 && $red === 0, "$built sky cells painted, $red red cells at tick 0");

// -- logo.ansi on disk matches the class (art regenerated from source) ----------
$stream = file_get_contents(__DIR__ . '/logo.ansi');
$check('logo.ansi == DupBanner::play() (art from class)', $stream === (new DupBanner('tc'))->play());

exit($fails === 0 ? 0 : 1);
