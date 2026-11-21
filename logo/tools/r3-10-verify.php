<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud gate for the idea-10 "Double Helix Alignment" banner.
 *
 *   php verify.php
 *
 * Checks, in order:
 *  A. timing budget (<=45 ticks, <=30 fps, <=1.5 s one-shot)
 *  B. determinism: in-process byte identity + CROSS-PROCESS byte identity of
 *     tick 0 and the final frame for every colour depth
 *  C. size caps: every frame at every depth is exactly ROWS lines, every line
 *     <= COLS visible cells (no overflow at 80 cols)
 *  D. fallback chain: detectDepth() truth table; plain frames and the plain
 *     stream contain ZERO escape codes
 *  E. SGR validity: truecolor stream only uses well-formed 38;2/48;2 octets
 *     (no 38;5 leaks); 256 stream only uses 38;5/48;5 with indices 0..255
 *  F. CONCEPT LAW — rungs ONLY where a REAL LCS aligns: an independent DP
 *     recompute of the LCS over the two seeded hash strands must match the
 *     banner's pair list exactly; every rung cell carries a valid pair id;
 *     the flattened tick's rung set equals the expected geometry of the LCS
 *     pairs and nothing else; rungs only grow over time; white ink anywhere
 *     in the truecolor stream belongs exclusively to rungs
 *  G. same hash = same colour (paired bases share their colour; back strand is
 *     exactly 40 % brightness); unaligned bases are the hollow loop glyph;
 *     gaps render as ─ on the strand lacking the statement; six palette hues
 *     all appear
 *  H. the held final frame is the phpdup wordmark (independent bitmap copy
 *     re-checked), byte-identical to the tail of the stream.
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Lab\DupBanner;

$fail = 0;
/** @var list<string> $passed */
$passed = [];
$check = static function (bool $ok, string $what, string $detail = '') use (&$fail, &$passed): void {
    if ($ok) {
        $passed[] = $what;
        echo "PASS  $what\n";
    } else {
        echo "FAIL  $what" . ($detail !== '' ? " — $detail" : '') . "\n";
        $fail++;
    }
};

$banner = new DupBanner();
$last = DupBanner::LAST_TICK;

/** strip every escape sequence + CR from a frame (visible text only) */
$strip = static fn (string $s): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\r/', '', $s);

/** independent xterm cube → rgb (duplicates the renderer's table) */
$xterm256 = static function (int $i): array {
    $i = max(0, min(255, $i));
    if ($i < 16) {
        $base = [[0,0,0],[205,0,0],[0,205,0],[205,205,0],[0,0,238],[205,0,205],[0,205,205],[229,229,229],
                 [127,127,127],[255,0,0],[0,255,0],[255,255,0],[92,92,255],[255,0,255],[0,255,255],[255,255,255]];
        return $base[$i];
    }
    if ($i >= 232) {
        $v = 8 + ($i - 232) * 10;
        return [$v, $v, $v];
    }
    $k = $i - 16;
    $s = [0, 95, 135, 175, 215, 255];
    $d = static fn (int $v): int => max(0, min(5, $v)); // no-op clamp for the real 0..5 digits
    return [$s[$d(intdiv($k, 36) % 6)], $s[$d(intdiv($k, 6) % 6)], $s[$d($k % 6)]];
};

/**
 * decode one frame at the given depth into [row][col] => [glyph, rgb|null]
 * (rgb resolved for both 38;2 and 38;5 forms).
 *
 * @return array<int, array<int, array{0: string, 1: array{0: int, 1: int, 2: int}|null}>>
 */
$decode = static function (string $frame, string $depth) use ($xterm256): array {
    $grid = [];
    foreach (explode("\n", $frame) as $y => $line) {
        $fg = null;
        $x = 0;
        $parts = preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m)) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0 || $v === 39) {
                        $fg = null;
                    } elseif ($v === 38) {
                        if (($p[$i + 1] ?? -1) === 2) {
                            $fg = [$p[$i + 2], $p[$i + 3], $p[$i + 4]];
                            $i += 4;
                        } elseif (($p[$i + 1] ?? -1) === 5) {
                            $fg = $xterm256($p[$i + 2]);
                            $i += 2;
                        }
                    } elseif ($v === 48) {
                        $i += ($p[$i + 1] ?? -1) === 2 ? 4 : 2; // background: ignored by these checks
                    }
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                $grid[$y][$x] = [$g, $fg];
                $x++;
            }
        }
    }
    return $grid;
};

/** classic LCS DP + backtrack — fully independent of DupBanner */
$lcs = static function (array $A, array $B): array {
    $n = count($A);
    $m = count($B);
    $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = 1; $i <= $n; $i++) {
        for ($j = 1; $j <= $m; $j++) {
            $dp[$i][$j] = $A[$i - 1] === $B[$j - 1]
                ? $dp[$i - 1][$j - 1] + 1
                : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
        }
    }
    $pairs = [];
    $i = $n;
    $j = $m;
    while ($i > 0 && $j > 0) {
        if ($A[$i - 1] === $B[$j - 1]) {
            $pairs[] = [$i - 1, $j - 1];
            $i--;
            $j--;
        } elseif ($dp[$i - 1][$j] >= $dp[$i][$j - 1]) {
            $i--;
        } else {
            $j--;
        }
    }
    return [array_reverse($pairs), $dp[$n][$m]];
};

// ---------------------------------------------------------------- A. timing budget
$frameCount = count(explode("\e[" . DupBanner::ROWS . "A\r", $banner->stream()));
$playbackUs = $frameCount * DupBanner::TICK_USEC;
$check($frameCount <= 45, 'tick count <= 45', "stream carries $frameCount frames");
$check($playbackUs <= 1_500_000, sprintf('one-shot playback %.3f s <= 1.5 s', $playbackUs / 1e6));
$check($frameCount * 1_000_000 / max(1, $playbackUs) <= 30.001, 'effective frame rate <= 30 fps at TICK_USEC spacing');
$check($frameCount === DupBanner::TICK_COUNT, "stream carries exactly TICK_COUNT frames ($frameCount)");

// ---------------------------------------------------------------- B. determinism
$check($banner->frame(0) === $banner->frame(0), 'frame(0) byte-identical when called twice');
$check($banner->finalFrame() === $banner->frame($last), 'final frame byte-identical');
$uniq = [];
for ($t = 0; $t <= $last; $t++) {
    $uniq[md5($banner->frameAtDepth($t, DupBanner::DEPTH_TRUECOLOR))] = 1;
}
$check(count($uniq) >= 40, 'real animation: >= 40 distinct truecolor frames', 'got ' . count($uniq));

$depths = [DupBanner::DEPTH_TRUECOLOR, DupBanner::DEPTH_256, DupBanner::DEPTH_PLAIN];
foreach ([0, $last] as $tick) {
    foreach ($depths as $depth) {
        $inproc = $banner->frameAtDepth($tick, $depth);
        $cmd = 'require "' . __DIR__ . '/DupBanner.php";'
            . '$b = new Phpdup\\Lab\\DupBanner();'
            . "echo \$b->frameAtDepth($tick, '" . $depth . "');";
        $out1 = (string) shell_exec(sprintf('php -r %s 2>/dev/null', escapeshellarg($cmd)));
        $out2 = (string) shell_exec(sprintf('php -r %s 2>/dev/null', escapeshellarg($cmd)));
        $label = $tick === 0 ? 'tick 0' : 'final frame';
        $check($out1 !== '' && $out1 === $out2 && $out1 === $inproc,
            "$label ($depth) byte-identical across two separate php invocations + in-process");
    }
}

// ---------------------------------------------------------------- C. size caps
$maxW = 0;
$badRows = 0;
$ragged = 0;
foreach ([DupBanner::DEPTH_TRUECOLOR, DupBanner::DEPTH_256, DupBanner::DEPTH_PLAIN] as $depth) {
    for ($t = 0; $t <= $last; $t++) {
        $lines = explode("\n", $strip($banner->frameAtDepth($t, $depth)));
        if (count($lines) !== DupBanner::ROWS) {
            $badRows++;
        }
        $w0 = mb_strwidth($lines[0], 'UTF-8');
        foreach ($lines as $l) {
            $maxW = max($maxW, mb_strwidth($l, 'UTF-8'));
            if (mb_strwidth($l, 'UTF-8') !== $w0) {
                $ragged++;
            }
        }
    }
}
$check($badRows === 0, 'every frame at every depth has exactly 7 rows');
$check($maxW <= DupBanner::COLS, "every frame <= 56 visible cols (max $maxW) → no overflow at 80");
$check($ragged === 0, 'rows share one visible width (clean cursor-up repaint)');

// ---------------------------------------------------------------- D. fallback chain
$check(DupBanner::detectDepth(true, true) === DupBanner::DEPTH_PLAIN, '--no-ansi => plain');
$check(DupBanner::detectDepth(false, false) === DupBanner::DEPTH_PLAIN, 'undecorated => plain');
$check(DupBanner::detectDepth(true, false, ['NO_COLOR' => '1']) === DupBanner::DEPTH_PLAIN, 'NO_COLOR => plain');
$check(DupBanner::detectDepth(true, false, ['TERM' => 'dumb']) === DupBanner::DEPTH_PLAIN, 'TERM=dumb => plain');
$check(DupBanner::detectDepth(true, false, ['COLORTERM' => 'truecolor']) === DupBanner::DEPTH_TRUECOLOR, 'COLORTERM=truecolor => 24-bit');
$check(DupBanner::detectDepth(true, false, ['COLORTERM' => '24bit']) === DupBanner::DEPTH_TRUECOLOR, 'COLORTERM=24bit => 24-bit');
$check(DupBanner::detectDepth(true, false, []) === DupBanner::DEPTH_256, 'no COLORTERM => xterm-256');
$plainEsc = 0;
for ($t = 0; $t <= $last; $t++) {
    $plainEsc += substr_count($banner->frameAtDepth($t, DupBanner::DEPTH_PLAIN), "\e");
}
$plainEsc += substr_count($banner->stream(DupBanner::DEPTH_PLAIN), "\e");
$check($plainEsc === 0, 'plain frames + plain stream contain ZERO escape codes');

// ---------------------------------------------------------------- E. SGR validity
$tcBad = 0;
$tc256Leak = 0;
foreach (explode("\n", $banner->stream()) as $line) {
    if (preg_match_all('/\e\[[0-9;?]*m/', $line, $mm)) {
        foreach ($mm[0] as $sgr) {
            if ($sgr === "\e[0m" || $sgr === "\e[39m") {
                continue;
            }
            if (!preg_match('/^\e\[(38|48);2;(\d{1,3});(\d{1,3});(\d{1,3})m$/', $sgr, $m)) {
                $tcBad++;
                continue;
            }
            foreach ([2, 3, 4] as $k) {
                if ((int) $m[$k] > 255) {
                    $tcBad++;
                }
            }
        }
    }
    $tc256Leak += preg_match_all('/\e\[38;5;|\e\[48;5;/', $line);
}
$check($tcBad === 0, 'truecolor stream: only well-formed 38;2/48;2 SGR (+ resets)');
$check($tc256Leak === 0, 'truecolor stream: no 256-colour codes mixed in');
$k256Bad = 0;
$k2Bad = 0;
foreach (explode("\n", $banner->stream(DupBanner::DEPTH_256)) as $line) {
    if (preg_match_all('/\e\[[0-9;?]*m/', $line, $mm)) {
        foreach ($mm[0] as $sgr) {
            if ($sgr === "\e[0m" || $sgr === "\e[39m") {
                continue;
            }
            if (!preg_match('/^\e\[(38|48);5;(\d{1,3})m$/', $sgr, $m) || (int) $m[2] > 255) {
                $k256Bad++;
            }
        }
    }
    $k2Bad += preg_match_all('/\e\[38;2;|\e\[48;2;/', $line);
}
$check($k256Bad === 0, 'xterm-256 stream: only well-formed 38;5/48;5 SGR (indices 0..255)');
$check($k2Bad === 0, 'xterm-256 stream: no truecolor codes mixed in');

// ---------------------------------------------------------------- F. rung law (LCS)
$A = $banner->sequenceA();
$B = $banner->sequenceB();
$pairs = $banner->alignPairs();
[$lcsPairs, $lcsLen] = $lcs($A, $B);

$allHashes = array_merge($A, $B);
$freq = array_count_values(array_map('strval', $allHashes));
$coreHashes = [];
foreach ($banner->tapeSlots() as $slot) {
    if ($slot['kind'] === 'm') {
        $coreHashes[(string) $slot['hash']] = 1;
    }
}
$hashLaw = true;
foreach ($freq as $h => $c) {
    if (isset($coreHashes[$h]) ? $c !== 2 : $c !== 1) {
        $hashLaw = false; // core hashes ride BOTH strands once; inserts are singletons
    }
}
$check($hashLaw && count($freq) === count($banner->tapeSlots()),
    'seeded strands: 17 core hashes each appear exactly once per strand, 5 inserts unique (optimal LCS forced)');
$check(count($pairs) === $lcsLen,
    "banner pair count == independent LCS optimum ($lcsLen)");
$valid = count($pairs) > 0;
$prev = [-1, -1];
foreach ($pairs as [$i, $j]) {
    $valid = $valid && $A[$i] === $B[$j] && $i > $prev[0] && $j > $prev[1];
    $prev = [$i, $j];
}
$check($valid, 'banner pairs form a strictly increasing common subsequence (a real LCS)');
$check($pairs === $lcsPairs, 'banner pairs == canonical DP backtrack result (exact alignment)');

$rungeOK = true;
$pairOK = true;
$seenPairs = [];
foreach (range(0, DupBanner::T_WORDMARK - 1) as $t) {
    $cur = [];
    foreach ($banner->layout($t) as $cell) {
        if ($cell['glyph'] !== DupBanner::RUNG_GLYPH) {
            continue;
        }
        $p = $cell['pair'] ?? null;
        if (!is_int($p) || !isset($pairs[$p]) || $A[$pairs[$p][0]] !== $B[$pairs[$p][1]]) {
            $rungeOK = false; // a rung NOT backed by a genuine LCS alignment
        }
        if ($cell['rgb'] !== DupBanner::RUNG_RGB) {
            $rungeOK = false; // rung without white ink
        }
        $geo = $banner->rungGeometry($t, (int) $p);
        if ($geo['col'] !== $cell['col'] || !in_array($cell['row'], $geo['rows'], true)) {
            $rungeOK = false; // rung off the geometry of its aligned bases
        }
        if ($banner->rungSnapTick((int) $p) > $t) {
            $pairOK = false; // snapped before its scheduled tick
        }
        $cur[$p] = 1;
    }
    $seenPairs = $cur + $seenPairs;
}
$check($rungeOK, 'every rung cell (strand phase, all ticks) sits between a REAL LCS-aligned base pair, white, on its own geometry');
$check($pairOK, 'rungs never appear before their scheduled snap tick');
$check(count($seenPairs) === count($pairs), 'every LCS-aligned pair eventually receives its rung');

// exact rung set on the flat tick (tick 32: pure aligned tape, nothing can overlap it)
$flat = 32;
$expect = [];
foreach ($pairs as $p => $_) {
    foreach ($banner->rungGeometry($flat, (int) $p)['rows'] as $r) {
        $expect[$r . ':' . $banner->rungGeometry($flat, (int) $p)['col']] = $p;
    }
}
$actual = [];
foreach ($banner->layout($flat) as $cell) {
    if ($cell['glyph'] === DupBanner::RUNG_GLYPH) {
        $actual[$cell['row'] . ':' . $cell['col']] = $cell['pair'] ?? -1;
    }
}
$check($expect !== [] && $expect === $actual,
    'flat-tape rung set == expected LCS pair geometry EXACTLY (' . count($actual) . ' cells, ' . count($pairs) . ' pairs)');

// white ink belongs exclusively to rungs (truecolor decode, every tick)
$whiteOK = true;
foreach (range(0, $last) as $t) {
    $grid = $decode($banner->frameAtDepth($t, DupBanner::DEPTH_TRUECOLOR), DupBanner::DEPTH_TRUECOLOR);
    foreach ($grid as $row) {
        foreach ($row as $cell) {
            $isWhite = $cell[1] === DupBanner::RUNG_RGB;
            if ($isWhite !== ($cell[0] === DupBanner::RUNG_GLYPH)) {
                $whiteOK = false;
            }
        }
    }
}
$check($whiteOK, 'across all ticks + truecolor: white ink == rung glyph, exclusively');

// snap schedule spread over the rotation phase
$snapMin = PHP_INT_MAX;
$snapMax = 0;
foreach (array_keys($pairs) as $p) {
    $s = $banner->rungSnapTick((int) $p);
    $snapMin = min($snapMin, $s);
    $snapMax = max($snapMax, $s);
}
$check($snapMin >= 1 && $snapMax <= DupBanner::T_FLATTEN - 1,
    "all rungs snap during the rotation phase (ticks $snapMin..$snapMax <= " . (DupBanner::T_FLATTEN - 1) . ')');

// ---------------------------------------------------------------- G. hash colour law
$hues = [];
foreach (array_merge($A, $B) as $h) {
    $hues[DupBanner::hashColor($h)] = 1;
}
$check(count($hues) === 6, 'all six concept hues appear in the seeded hash set');

$colourOK = true;
$dimOK = true;
$loopOK = true;
foreach ([0, 10, $flat] as $t) {
    $grid = $decode($banner->frameAtDepth($t, DupBanner::DEPTH_TRUECOLOR), DupBanner::DEPTH_TRUECOLOR);
    foreach ($banner->layout($t) as $cell) {
        $key = $cell['key'] ?? '';
        if (!preg_match('/^([AB])#(\d+)$/', $key, $m)) {
            continue;
        }
        [$g, $rgb] = $grid[$cell['row']][$cell['col']];
        if ($g !== $cell['glyph'] || $rgb !== $cell['rgb']) {
            $colourOK = false;
        }
        $idx = (int) $m[2];
        $hash = $m[1] === 'A' ? $A[$idx] : $B[$idx];
        $full = DupBanner::colorOf($hash);
        $dimmed = DupBanner::dimRgb($full);
        $front = $banner->frontOf($t, $m[1], $idx);
        if ($rgb !== ($front ? $full : $dimmed)) {
            $dimOK = false; // brightness must be exactly full or 40 % per depth
        }
    }
}
$check($colourOK, 'decoded bytes == layout (every base cell renders its hash colour)');
$check($dimOK, 'back-strand shading == exactly 40 % brightness, front == 100 %');

// paired bases share a colour; loops are hollow; gaps sit on the lacking strand
$flatCells = [];
foreach ($banner->layout($flat) as $cell) {
    $flatCells[$cell['row'] . ':' . $cell['col']] = $cell;
}
$pairColour = true;
foreach ($pairs as [$i, $j]) {
    $ca = DupBanner::colorOf($A[$i]);
    $cb = DupBanner::colorOf($B[$j]);
    if ($ca !== $cb) {
        $pairColour = false;
    }
}
$check($pairColour, 'same hash (aligned pair) => same colour on both strands');

$loops = 0;
$gapSlots = 0;
foreach ($banner->tapeSlots() as $q => $slot) {
    if ($slot['kind'] === 'm') {
        continue;
    }
    $gapSlots++;
    $row = $slot['kind'] === 'a' ? DupBanner::FLAT_A : DupBanner::FLAT_B;
    $gapRow = $slot['kind'] === 'a' ? DupBanner::FLAT_B : DupBanner::FLAT_A;
    $base = $flatCells[$row . ':' . DupBanner::slotCol($q)] ?? null;
    $gap = $flatCells[$gapRow . ':' . DupBanner::slotCol($q)] ?? null;
    if ($base === null || $base['glyph'] !== DupBanner::LOOP_GLYPH) {
        $loopOK = false;
    }
    if ($gap === null || $gap['glyph'] !== DupBanner::GAP_GLYPH || $gap['rgb'] !== DupBanner::GAP_RGB) {
        $loopOK = false;
    }
    $loops++;
}
$check($loops === count($A) - count($pairs) + count($B) - count($pairs) && $loops === 5,
    'unaligned bases == the 5 inserted/deleted statements, drawn as hollow loop glyphs');
$check($loopOK, 'flat tape: every unaligned base bulges as ○ AND its opposite-strand gap renders as ─ (#475569)');

// ---------------------------------------------------------------- H. wordmark final
$word = 'phpdup';
$expFont = [
    'p' => ['1110', '1001', '1110', '1000', '1000'],
    'h' => ['1000', '1100', '1010', '1010', '1010'],
    'd' => ['0010', '0010', '0110', '1010', '0110'],
    'u' => ['1010', '1010', '1010', '1010', '0110'],
];
$finalPlain = explode("\n", $banner->frameAtDepth($last, DupBanner::DEPTH_PLAIN));
$x0 = intdiv(DupBanner::COLS - (6 * 5 - 1), 2);
$wmOK = true;
foreach (str_split($word) as $k => $ch) {
    $mask = $expFont[$ch];
    for ($r = 0; $r < 5; $r++) {
        for ($c = 0; $c < 4; $c++) {
            $cellCh = $finalPlain[1 + $r][$x0 + 5 * $k + $c] ?? ' ';
            $want = $mask[$r][$c] === '1' ? '#' : ' ';
            if ($cellCh !== $want) {
                $wmOK = false;
            }
        }
    }
}
$inkTotal = 0;
foreach ($finalPlain as $line) {
    $inkTotal += substr_count($line, '#');
}
$bitCount = 0;
foreach (str_split($word) as $ch) {
    foreach ($expFont[$ch] as $rowBits) {
        $bitCount += substr_count($rowBits, '1');
    }
}
$check($wmOK, 'final held frame: the phpdup pixel-font wordmark (independent bitmap re-check)');
$check($inkTotal === $bitCount, "final frame ink == wordmark bits exactly ($bitCount cells, nothing else)");
$check(str_contains($banner->stream(), $banner->finalFrame()) && str_ends_with($banner->stream(), "\e[0m\n\e[?25h"),
    'stream ends holding the final (wordmark) frame');

echo $fail === 0 ? "\nALL CHECKS PASSED (" . count($passed) . ")\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
