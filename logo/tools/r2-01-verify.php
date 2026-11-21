<?php

/**
 * verify.php — fail-loud assertions for the DupBanner logo against the owner's
 * hard constraints. Exits non-zero on the first violation.
 *
 *   php verify.php
 */

declare(strict_types=1);

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
        $w[] = mb_strlen(preg_replace('/\e\[[0-9;]*m/', '', $l) ?? '');
    }
    return $w;
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
$cmd = 'php -r %s';
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

echo "\n3. Size caps\n";
check(DupBanner::TICKS <= 45, 'tick count <= 45', ['ticks' => DupBanner::TICKS]);
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
check($allOver === [], 'no frame exceeds 80 cols', $allOver);

echo "\n4. Fallbacks\n";
check(!str_contains($plain->plain(), "\e"), 'plain fallback contains ZERO escape codes');
check(str_contains($tc->frame($finalTick), '48;2;'), 'tc frame uses 24-bit SGR (48;2)');

echo "\n5. 256-colour stream is valid SGR only\n";
preg_match_all('/\e\[[0-9;]*m/', $x256->play(), $m);
$bad = [];
foreach ($m[0] as $seq) {
    if ($seq === "\e[0m" || $seq === "\e[49m") {
        continue;
    }
    if (preg_match('/^\e\[48;5;(\d{1,3})m$/', $seq, $mm) && (int) $mm[1] <= 255) {
        continue;
    }
    $bad[] = $seq;
}
check($bad === [], 'every 256-mode SGR is 0/49/48;5;N (N<=255)', array_slice($bad, 0, 5));
check(!str_contains($x256->play(), '48;2;'), '256-mode emits no truecolor SGR');
check(!str_contains($x256->play(), '38;2;'), '256-mode emits no truecolor fg SGR');

echo "\n6. Final frame shape == plain shape\n";
// Reconstruct occupancy by counting painted (48;2) vs unpainted (49/default) cells.
// Walk each glyph run, applying the SGR active just before it; runs before any SGR
// on a line are unpainted (encode only emits an escape when bg CHANGES).
$tcMask = [];
foreach (preg_split("/\n/", rtrim($tc->frame($finalTick), "\n")) ?: [] as $l) {
    $row = '';
    $activePainted = false;   // background state; '49'/reset == unpainted
    foreach (preg_split('/(\e\[[0-9;]*m)/', $l, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
        if ($tok === '') {
            continue;
        }
        if ($tok[0] === "\e") {
            $activePainted = (bool) preg_match('/^\e\[48;2;/', $tok);
            continue;
        }
        $row .= str_repeat($activePainted ? '#' : ' ', mb_strlen($tok));
    }
    $tcMask[] = $row;
}
$plainMask = array_map(
    static fn (string $l): string => str_replace('#', '#', $l),
    explode("\n", rtrim($plain->plain(), "\n")),
);
check(
    array_map('rtrim', $tcMask) === array_map('rtrim', $plainMask),
    'tc final occupancy equals plain occupancy',
    ['tc' => array_map('rtrim', $tcMask), 'plain' => array_map('rtrim', $plainMask)],
);

echo "\n";
if ($fail > 0) {
    fwrite(STDERR, "VERIFY FAILED: $fail assertion(s)\n");
    exit(1);
}
echo "ALL CHECKS PASSED\n";
