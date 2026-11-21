<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud assertions for the "Glitch Dedupe" DupBanner logo
 * against the owner's hard constraints. Exits non-zero on the first violation.
 *
 *   php verify.php
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
    echo "  FAIL  $msg" . ($ctx ? '  ' . json_encode($ctx) : '') . "\n";
}

/**
 * Strip every CSI colour sequence, return the per-line visible widths.
 *
 * @return list<int>
 */
function widths(string $frame): array
{
    $lines = preg_split("/\n/", rtrim($frame, "\n")) ?: [];
    $w = [];
    foreach ($lines as $l) {
        $w[] = mb_strlen(preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $l) ?? '');
    }
    return $w;
}

/**
 * Parse a truecolor frame into per-cell [char, "r,g,b"|null] occupancy:
 * foreground changes on 38;2;… sequences, bare cells (space) carry none.
 *
 * @return list<list<array{0:string,1:string|null}>>
 */
function cells(string $frame): array
{
    $rows = [];
    foreach (preg_split("/\n/", rtrim($frame, "\n")) ?: [] as $line) {
        $row = [];
        $fg = null;
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            if ($tok === '') {
                continue;
            }
            if ($tok[0] === "\e") {
                if (preg_match('/^\e\[0m$/', $tok)) {
                    $fg = null;
                } elseif (preg_match('/^\e\[38;2;(\d+);(\d+);(\d+)m$/', $tok, $m)) {
                    $fg = "{$m[1]},{$m[2]},{$m[3]}";
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                $row[] = [$g, $g === ' ' ? null : $fg];
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Occupancy string per row from parsed cells: block glyphs -> '#', text kept.
 *
 * @param list<list<array{0:string,1:string|null}>> $cs
 * @return list<string>
 */
function occupancy(array $cs): array
{
    return array_map(
        static fn (array $row): string => rtrim(implode('', array_map(
            static function (array $c): string {
                if ($c[1] === null) {
                    return ' ';
                }
                return in_array($c[0], ['█', '▓', '▄'], true) ? '#' : $c[0];
            },
            $row,
        ))),
        $cs,
    );
}

$tc = new DupBanner('tc');
$x256 = new DupBanner('256');
$plain = new DupBanner('plain');

$finalTick = DupBanner::TICKS - 1;

echo "1. Determinism (same process)\n";
check($tc->frame(0) === $tc->frame(0), 'tc frame(0) byte-identical on repeat');
check($tc->frame($finalTick) === $tc->frame($finalTick), 'tc final frame byte-identical on repeat');
check($x256->frame(0) === $x256->frame(0), '256 frame(0) byte-identical on repeat');

echo "\n2. Determinism (separate php invocations)\n";
$hashExpr = function (string $depth, int $tick): string {
    return "require __DIR__.'/DupBanner.php';"
        . "\$b=new Phpdup\\Branding\\DupBanner('$depth');"
        . "echo md5(\$b->frame($tick));";
};
foreach ([['tc', 0], ['tc', $finalTick], ['256', 0], ['256', $finalTick], ['plain', $finalTick]] as [$d, $t]) {
    $a = trim((string) shell_exec(sprintf('php -r %s', escapeshellarg($hashExpr($d, $t)))));
    $b = trim((string) shell_exec(sprintf('php -r %s', escapeshellarg($hashExpr($d, $t)))));
    check($a !== '' && $a === $b, "$d tick $t md5 stable across processes", ['a' => $a, 'b' => $b]);
}

echo "\n3. Size + tick caps\n";
check(DupBanner::TICKS <= 45, 'tick count <= 45', ['ticks' => DupBanner::TICKS]);
check(DupBanner::TICKS * DupBanner::TICK_MS <= 1500, 'playback <= 1.5 s', ['ms' => DupBanner::TICKS * DupBanner::TICK_MS]);
check(1000 / DupBanner::TICK_MS <= 30.0, 'fps <= 30', ['fps' => round(1000 / DupBanner::TICK_MS, 1)]);
$fw = widths($tc->frame($finalTick));
check(count($fw) <= 7, 'final frame rows <= 7', ['rows' => count($fw)]);
check(max($fw) <= 56, 'final frame cols <= 56', ['max' => max($fw)]);
$allOver = [];
for ($t = 0; $t < DupBanner::TICKS; $t++) {
    foreach (widths($tc->frame($t)) as $wline) {
        if ($wline > 80) {
            $allOver[] = "t$t:$wline";
        }
    }
}
check($allOver === [], 'no frame exceeds 80 cols', array_slice($allOver, 0, 5));

echo "\n4. Fallbacks\n";
check(!str_contains($plain->plain(), "\e"), 'plain fallback contains ZERO escape codes');
check(str_contains($tc->frame($finalTick), '38;2;'), 'tc frame uses 24-bit fg SGR (38;2)');
check(str_contains($tc->frame($finalTick), '48;2;13;2;33m'), 'tc canvas bg is #0D0221 (48;2)');

echo "\n5. 256-colour stream is valid SGR only\n";
preg_match_all('/\e\[[0-9;]*m/', $x256->play(), $m);
$bad = [];
foreach ($m[0] as $seq) {
    if ($seq === "\e[0m") {
        continue;
    }
    if (preg_match('/^\e\[([34]8;5;(\d{1,3}))m$/', $seq, $mm) && (int) $mm[2] <= 255) {
        continue;
    }
    $bad[] = $seq;
}
check($bad === [], 'every 256-mode SGR is 0 / 38;5;N / 48;5;N (N<=255)', array_slice($bad, 0, 5));
check(!str_contains($x256->play(), '48;2;'), '256-mode emits no truecolor bg SGR');
check(!str_contains($x256->play(), '38;2;'), '256-mode emits no truecolor fg SGR');

echo "\n6. Final frame = clean wordmark, zero ghost channels\n";
$finalCells = cells($tc->render());
$fgs = [];
foreach ($finalCells as $row) {
    foreach ($row as [$ch, $fg]) {
        if ($fg !== null) {
            $fgs[$fg] = ($fgs[$fg] ?? 0) + 1;
        }
    }
}
// allowed: #F1F5F9 mark, #FFFFFF single glint, #4ADE80 counter-at-zero
$allowed = ['241,245,249' => true, '255,255,255' => true, '74,222,128' => true];
check(array_diff(array_keys($fgs), array_keys($allowed)) === [], 'final frame has no red/cyan/amber ghost channels', $fgs);
check(($fgs['255,255,255'] ?? 0) === 1, 'final frame carries exactly one white glint cell', $fgs);
$ghost = ['255,42,109', '5,217,232', '251,191,36'];
$present = [];
foreach ($ghost as $g) {
    if (isset($fgs[$g])) {
        $present[] = $g;
    }
}
check($present === [], 'no ghost-channel colour survives the hold', $present);

echo "\n7. Final occupancy == base wordmark + counter (and == plain)\n";
$tcOcc = occupancy($finalCells);
$plainOcc = array_map('rtrim', explode("\n", rtrim($plain->plain(), "\n")));
check($tcOcc === $plainOcc, 'tc final occupancy equals plain occupancy', ['tc' => $tcOcc, 'plain' => $plainOcc]);
check(count($tcOcc) === 7, 'final frame is 7 rows', ['rows' => count($tcOcc)]);
// Mark rows must be pure block cells (no scanner/outline glyphs left over).
$strays = [];
foreach (array_slice($finalCells, 0, DupBanner::MARK_ROWS) as $y => $row) {
    foreach ($row as [$ch, $fg]) {
        if ($fg !== null && $ch !== '█') {
            $strays[] = "r$y:$ch";
        }
    }
}
check($strays === [], 'final mark rows are clean solid cells (no ▓/▄ residue)', array_slice($strays, 0, 5));

echo "\n8. Hold semantics\n";
check($tc->frame(DupBanner::TICKS) === $tc->frame($finalTick), 'frame(TICKS) clamps to the held final frame');
check($tc->frame(9999) === $tc->frame($finalTick), 'frame(9999) clamps to the held final frame');
check($tc->render() === $tc->frame($finalTick), 'render() equals the held final frame');
$lastJoined = substr($tc->play(), -strlen($tc->frame($finalTick)));
check(str_contains($tc->play(), $tc->frame($finalTick)), 'play stream ends on the final frame');

echo "\n9. Story beats present (fail-loud on silent rot)\n";
$stream = $tc->play();
check(str_contains($stream, "\e[38;2;255;42;109m"), 'red channel ghost appears somewhere');
check(str_contains($stream, "\e[38;2;5;217;232m"), 'cyan channel ghost appears somewhere');
check(str_contains($stream, "\e[38;2;251;191;36m"), 'amber scanner/outline appears somewhere');
$t34 = cells($tc->frame(34));
$hasDup = false;
foreach ($t34 as $row) {
    foreach ($row as [, $fg]) {
        if ($fg === '255,42,109' || $fg === '5,217,232') {
            $hasDup = true;
        }
    }
}
check($hasDup, 'mid-animation frames carry the glitched duplicates');
check(DupBanner::aliveCount(0) === 9, 'counter starts at duplicated: 9', ['n' => DupBanner::aliveCount(0)]);
check(DupBanner::aliveCount($finalTick) === 0, 'counter ends at duplicated: 0', ['n' => DupBanner::aliveCount($finalTick)]);

echo "\n";
if ($fail > 0) {
    fwrite(STDERR, "VERIFY FAILED: $fail assertion(s)\n");
    exit(1);
}
echo "ALL CHECKS PASSED\n";
