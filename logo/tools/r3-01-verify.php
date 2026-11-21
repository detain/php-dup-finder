<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud assertions for the Dot-Plot DupBanner against every
 * owner hard constraint. Exits non-zero on the first violation count.
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
 * Visible column widths per line, escapes stripped.
 *
 * @return list<int>
 */
function widths(string $frame): array
{
    $lines = preg_split("/\n/", rtrim($frame, "\n")) ?: [];
    return array_map(static fn (string $l): int => mb_strlen(preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $l) ?? ''), $lines);
}

$tc = new DupBanner('tc');
$x256 = new DupBanner('256');
$plain = new DupBanner('plain');
$final = DupBanner::TICKS - 1;

echo "1. Same-process determinism\n";
check($tc->frame(0) === $tc->frame(0), 'tc frame(0) byte-identical on repeat');
check($tc->frame($final) === $tc->frame($final), 'tc final frame byte-identical on repeat');

echo "\n2. Cross-process determinism (separate php invocations)\n";
$abs = __DIR__ . '/DupBanner.php';
foreach ([['tc', 0], ['tc', $final], ['256', 0], ['256', $final], ['plain', $final]] as [$d, $t]) {
    $expr = $d === 'plain'
        ? "require '$abs'; echo md5((new Phpdup\\Branding\\DupBanner('$d'))->plain());"
        : "require '$abs'; echo md5((new Phpdup\\Branding\\DupBanner('$d'))->frame($t));";
    $a = trim((string) shell_exec('php -r ' . escapeshellarg($expr)));
    $b = trim((string) shell_exec('php -r ' . escapeshellarg($expr)));
    check($a !== '' && $a === $b, "$d " . ($d === 'plain' ? 'plain' : "tick $t") . ' md5 stable across processes', ['a' => $a, 'b' => $b]);
}

echo "\n3. Budget + size caps\n";
check(DupBanner::TICKS <= 45, 'tick count <= 45', ['ticks' => DupBanner::TICKS]);
check(DupBanner::FPS <= 30, 'fps <= 30', ['fps' => DupBanner::FPS]);
$fw = widths($tc->frame($final));
check(count($fw) <= 7, 'final frame rows <= 7', ['rows' => count($fw)]);
check(max($fw) <= 56, 'final frame cols <= 56', ['max' => max($fw)]);
$over = [];
for ($t = 0; $t < DupBanner::TICKS; $t++) {
    foreach (widths($tc->frame($t)) as $i => $wline) {
        if ($wline > 80) {
            $over[] = "t$t r$i w$wline";
        }
    }
}
check($over === [], 'no frame exceeds 80 cols anywhere', array_slice($over, 0, 4));
check(substr_count($tc->stream(), "\e[H") === DupBanner::TICKS, 'stream holds exactly TICKS frames', ['n' => substr_count($tc->stream(), "\e[H")]);

echo "\n4. Fallback chain\n";
check(!str_contains($plain->plain(), "\e"), 'plain fallback contains ZERO escape codes');
check(str_contains($tc->frame($final), '38;2;') && str_contains($tc->frame($final), '48;2;'), 'tc frame uses 24-bit SGR (38;2 + 48;2)');

echo "\n5. 256-colour stream is valid SGR only\n";
$stream256 = $x256->stream();
preg_match_all('/\e\[[0-9;?]*[A-Za-z]?/', $stream256, $m);
$bad = [];
foreach ($m[0] as $seq) {
    if (in_array($seq, ["\e[0m", "\e[H", "\e[2J", "\e[?25l", "\e[?25h"], true)) {
        continue;
    }
    if (preg_match('/^\e\[(38|48);5;(\d{1,3})m$/', $seq, $mm) && (int) $mm[2] <= 255) {
        continue;
    }
    $bad[] = $seq;
}
check($bad === [], '256 mode emits only 38;5;N / 48;5;N (N<=255) + cursor/reset', array_slice($bad, 0, 5));
check(!str_contains($stream256, '38;2;') && !str_contains($stream256, '48;2;'), '256 mode emits no truecolor SGR');
check(str_contains($stream256, "\e[H") && str_contains($stream256, "\e[0m"), '256 mode is a real animation stream');

echo "\n6. Honest data: three spliced clones detected from the token arrays\n";
[$litCount, $A, $B, $streaks] = $tc->summary();
check(count($A) === 112 && count($B) === 28, 'A=112 tokens, B=28 tokens');
check($litCount >= 150 && $litCount <= 500, 'speckle field density sane', ['lit' => $litCount]);
check(count($streaks) === 3, 'exactly 3 streaks detected', ['got' => count($streaks), 'streaks' => $streaks]);
$types = array_map(static fn (array $s): string => $s['type'], $streaks);
check($types === ['exact', 'type2', 'swap'], 'streak kinds in X order: exact, type2, swap', ['types' => $types]);
check(min(array_map(static fn (array $s): int => $s['length'], $streaks)) >= 6, 'each streak >= 6 dots', ['lengths' => array_map(static fn (array $s): int => $s['length'], $streaks)]);

echo "\n7. Composition checks on the final frame\n";
$cells = $tc->cells($final);
$counts = ['blank' => 0, 'speckle' => 0, 'box' => 0, 'streak' => 0, 'word' => 0];
foreach ($cells as $row) {
    foreach ($row as $cell) {
        $counts[['blank', 'speckle', 'box', 'streak', 'word'][$cell['cls']]]++;
    }
}
check($counts['word'] === 53, 'wordmark block-cell count == 53 (font on-pixels)', ['got' => $counts['word']]);
check($counts['streak'] > 0 && $counts['box'] > 0 && $counts['speckle'] > 0, 'final frame shows streaks + boxes + speckle', $counts);
$pw = $plain->plain();
check(str_contains($pw, 'X') && str_contains($pw, '+') && str_contains($pw, '.') && str_contains($pw, '#'), 'plain frame carries all four glyph classes');

echo "\n8. Animation narrative (stages actually change pixels)\n";
$f0 = $tc->frame(0);
$f10 = $tc->frame(10);
$f31 = $tc->frame(31);
$f44 = $tc->frame($final);
check($f0 !== $f10 && $f10 !== $f31 && $f31 !== $f44, 'wipe / glow / settle frames differ');
check(str_contains($f44, "\e[38;2;236;254;255m"), 'final frame holds peak-white wordmark');
check(!str_contains($f10, "\e[38;2;236;254;255m"), 'mid-wipe frame has no peak yet');
$c31 = new DupBanner('tc');
$glow = $c31->cells(31);
$streakSeen = false;
foreach ($glow as $row) {
    foreach ($row as $cell) {
        if ($cell['cls'] === 3) {
            $streakSeen = true;
        }
    }
}
check($streakSeen, 'streaks lit in the glow window');

echo "\n";
if ($fail > 0) {
    fwrite(STDERR, "VERIFY FAILED: $fail assertion(s)\n");
    exit(1);
}
echo "ALL CHECKS PASSED\n";
