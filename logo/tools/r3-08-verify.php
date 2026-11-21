<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud constraint gate for the Photocopy Drift logo.
 * Any violation prints FAIL with the check name and exits non-zero.
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Cli\DupBanner;

$fails = 0;

/** @param callable():bool $fn */
function check(string $name, callable $fn): void
{
    global $fails;
    try {
        if ($fn()) {
            echo "ok    {$name}\n";
            return;
        }
        echo "FAIL  {$name}\n";
    } catch (Throwable $e) {
        echo "FAIL  {$name}: " . $e->getMessage() . "\n";
    }
    $fails++;
}

/** Independent char-bigram Jaccard (deliberately NOT calling the class). */
function indepSim(string $a, string $b): float
{
    $bag = static function (string $s): array {
        $out = [];
        for ($i = 0; $i < strlen($s) - 1; $i++) {
            $out[substr($s, $i, 2)] = 1;
        }
        return $out;
    };
    $A = $bag($a);
    $B = $bag($b);
    $inter = count(array_intersect_key($A, $B));
    $union = count(array_merge($A, $B));
    return $union > 0 ? $inter / $union : 1.0;
}

$stripSgr = static fn (string $s): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $s);

// ---------------------------------------------------------------- determinism

check('tick 0 + final byte-stable in-process', static function (): bool {
    return DupBanner::frame(0) === DupBanner::frame(0)
        && DupBanner::finalFrame() === DupBanner::finalFrame();
});

check('all 45 ticks byte-stable in-process', static function (): bool {
    for ($t = 0; $t < DupBanner::FRAMES; $t++) {
        if (DupBanner::frame($t) !== DupBanner::frame($t)) {
            return false;
        }
    }
    return true;
});

check('cross-process determinism: tick 0 + final identical in fresh php', static function (): bool {
    $code = 'require $argv[1]; echo \\Phpdup\\Cli\\DupBanner::frame(0); echo "###"; echo \\Phpdup\\Cli\\DupBanner::finalFrame();';
    $a = (string) shell_exec('php -r ' . escapeshellarg($code) . ' ' . escapeshellarg(__DIR__ . '/DupBanner.php'));
    $b = (string) shell_exec('php -r ' . escapeshellarg($code) . ' ' . escapeshellarg(__DIR__ . '/DupBanner.php'));
    $expected = DupBanner::frame(0) . '###' . DupBanner::finalFrame();
    return $a === $expected && $b === $expected;
});

// ------------------------------------------------------------------ geometry

check('final frame: <= 7 rows, each <= 56 cols, none > 80', static function () use ($stripSgr): bool {
    $rows = explode("\n", $stripSgr(DupBanner::finalFrame()));
    if (count($rows) > DupBanner::ROWS || DupBanner::ROWS > 7) {
        return false;
    }
    foreach ($rows as $r) {
        if (mb_strwidth(rtrim($r)) > 56) {
            return false;
        }
    }
    return true;
});

check('every tick paints full 7x56 canvas (no short frames)', static function () use ($stripSgr): bool {
    for ($t = 0; $t < DupBanner::FRAMES; $t++) {
        $rows = explode("\n", DupBanner::frame($t));
        if (count($rows) !== 7) {
            return false;
        }
        foreach ($rows as $r) {
            if (mb_strwidth($stripSgr($r)) !== 56) {
                return false;
            }
        }
    }
    return true;
});

// --------------------------------------------------------------- fallbacks

check('plain fallback contains zero ESC bytes (frame + play)', static function (): bool {
    return !str_contains(DupBanner::text(), "\e")
        && !str_contains(DupBanner::play(DupBanner::DEPTH_PLAIN), "\e")
        && !str_contains(DupBanner::render(DupBanner::LAST_TICK, DupBanner::DEPTH_PLAIN), "\e");
});

check('plain fallback is non-empty and holds the wordmark glyphs', static function (): bool {
    $t = DupBanner::text();
    return trim($t) !== '' && str_contains($t, '█');
});

check('resolveDepth honours NO_COLOR / COLORTERM / TERM=256 / dumb', static function (): bool {
    putenv('NO_COLOR=1');
    putenv('COLORTERM=');
    $a = DupBanner::resolveDepth();
    putenv('NO_COLOR');
    putenv('COLORTERM=truecolor');
    $b = DupBanner::resolveDepth();
    putenv('COLORTERM=24bit');
    $c = DupBanner::resolveDepth();
    putenv('COLORTERM=');
    putenv('TERM=xterm-256color');
    $d = DupBanner::resolveDepth();
    putenv('TERM=dumb');
    $e = DupBanner::resolveDepth();
    putenv('TERM');
    return $a === 'plain' && $b === 'tc' && $c === 'tc' && $d === '256' && $e === 'plain';
});

check('256 path: valid SGR only (38;5/48;5, 0<=n<=255), never 38;2', static function (): bool {
    $f = DupBanner::render(30, DupBanner::DEPTH_256);
    if (str_contains($f, '38;2') || str_contains($f, '48;2')) {
        return false;
    }
    preg_match_all('/\e\[([0-9;]*)m/', $f, $m);
    foreach ($m[1] as $params) {
        if ($params === '0' || $params === '4') {
            continue;
        }
        if (!preg_match('/^(?:4;)?38;5;(\d{1,3});48;5;(\d{1,3})$/', $params, $g)) {
            return false;
        }
        if ((int) $g[1] > 255 || (int) $g[2] > 255) {
            return false;
        }
    }
    return true;
});

check('truecolor path uses 38;2/48;2 SGR', static function (): bool {
    $f = DupBanner::frame(0);
    return str_contains($f, "\e[38;2;") && str_contains($f, ";48;2;") && str_contains($f, "\e[0m");
});

check('underline SGR (4) present once aligned, absent pre-align', static function (): bool {
    $aligned = DupBanner::frame(28);
    $pre = DupBanner::frame(20);
    return str_contains($aligned, "\e[4;") && !str_contains($pre, "\e[4;");
});

// -------------------------------------------------------------- timing caps

check('animation budget: <=45 ticks, <=30 fps, <=1.5 s', static function (): bool {
    return DupBanner::FRAMES <= 45
        && DupBanner::TICK_MS >= 33
        && DupBanner::FRAMES * DupBanner::TICK_MS <= 1500;
});

check('play stream: 45 frames, holds final frame, cursor restored', static function (): bool {
    $stream = DupBanner::play(DupBanner::DEPTH_TC);
    $frames = preg_split('/\e\[' . DupBanner::ROWS . 'A\r/', $stream);
    if ($frames === false || count($frames) !== DupBanner::FRAMES) {
        return false;
    }
    $last = (string) end($frames);
    return str_contains($last, DupBanner::finalFrame())
        && str_ends_with($last, "\e[?25h");
});

// ------------------------------------------------- computed-similarity law

check('similarities are COMPUTED: class values == independent reimplementation', static function (): bool {
    $gens = DupBanner::generations();
    if ($gens !== ['phpdupp', 'phpdu', 'ppdup']) {
        return false; // mutation scripts drifted from the design
    }
    foreach ($gens as $i => $g) {
        if (abs(DupBanner::simToMaster($i) - indepSim(DupBanner::MASTER, $g)) > 1e-12) {
            return false;
        }
    }
    return true;
});

check('outlier follows the REAL CoherenceAnalyzer rule (mean pairwise < 0.6)', static function (): bool {
    // Independent mean-pairwise computation, same rule as the analyzer.
    $members = array_merge([DupBanner::MASTER], DupBanner::generations());
    $flagged = [];
    foreach ($members as $i => $m) {
        $sum = 0.0;
        $n = 0;
        foreach ($members as $j => $o) {
            if ($i === $j) {
                continue;
            }
            $sum += indepSim($m, $o);
            $n++;
        }
        if ($n > 0 && $sum / $n < 0.6) {
            $flagged[] = $i;
        }
    }
    $classOutlier = DupBanner::outlierGen();
    return $classOutlier !== null
        && $flagged === [$classOutlier + 1]                 // only the amber gen
        && DupBanner::OUTLIER_THRESHOLD === 0.6;            // analyzer default
});

check('threshold semantics are STRICT < : gen2 mean == 0.6000 exactly, NOT flagged', static function (): bool {
    return abs(DupBanner::meanPairwise(2) - 0.6) < 1e-12 && DupBanner::outlierGen() === 2;
});

check('amber palette appears ONLY on the flagged gen while it is on screen', static function (): bool {
    $t34 = DupBanner::frame(34);
    // gen3 card is the amber one: amber ink 245;158;11 (tag) must be there,
    // while gens 1-2 readouts keep their paper tints (no amber on their rows).
    $rows = explode("\n", $t34);
    $amberOnGen3 = str_contains($rows[5], '38;2;245;158;11') || str_contains($rows[5], '38;2;146;64;14');
    $amberElsewhere = str_contains($rows[3], '245;158;11') || str_contains($rows[4], '245;158;11');
    return $amberOnGen3 && !$amberElsewhere;
});

check('badge: cyan x3 on final, absent at t40', static function (): bool {
    return str_contains(DupBanner::finalFrame(), '38;2;34;211;238')
        && !str_contains(DupBanner::frame(40), '38;2;34;211;238');
});

check('outlier gone + survivors collapsed on final frame', static function () use ($stripSgr): bool {
    $final = $stripSgr(DupBanner::finalFrame());
    return !str_contains($final, 's=') && !str_contains($final, 'outlier')
        && substr_count($final, 'php') === 0 && substr_count($final, 'ppdup') === 0;
});

exit($fails === 0 ? 0 : 1);
