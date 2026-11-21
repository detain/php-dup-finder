<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud gate for the Union-Find Constellations DupBanner.
 *
 * Checks: cross-process determinism, size caps, timing budget, truecolor +
 * xterm-256 + zero-escape plain fallback chain, and — the heart of the
 * concept — that every displayed component colour equals the hue of the
 * union-find root as computed by an INDEPENDENT re-implementation here, that
 * the three p letters share one hue, that the threshold gate is real, and
 * that path compression (spark straight to root) is shown at least twice.
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

// ---------------------------------------------------------------- concept census
$wordmark = array_values(array_filter(DupBanner::STARS, static fn (array $s): bool => !$s['singleton']));
$decoys = count(DupBanner::STARS) - count($wordmark);
$check(count(DupBanner::STARS) >= 38 && count(DupBanner::STARS) <= 46, '~30 stars + ~8 decoys (total ' . count(DupBanner::STARS) . ')');
$check(count($wordmark) === 33 && $decoys === 8, "33 wordmark vertices + $decoys decoy singletons");
$check(count(DupBanner::EDGE_PAIRS) === 31, '31 scored edges');

// ---------------------------------------------------------------- determinism (in-process)
$check($banner->frame(0) === $banner->frame(0), 'frame(0) byte-identical when called twice');
$check($banner->finalFrame() === $banner->frame($last), 'final frame byte-identical');
$check($banner->frame(0) !== $banner->frame(1), 'frame(1) differs from frame(0) (real animation)');
$check($banner->frameAtDepth(0, DupBanner::DEPTH_256) === $banner->frameAtDepth(0, DupBanner::DEPTH_256), '256 frame(0) deterministic');

// ---------------------------------------------------------------- determinism (cross-process)
foreach ([['tick 0', 0], ['final frame', $last]] as [$label, $tick]) {
    $spawn = static fn (): string => (string) shell_exec(sprintf('php -r %s 2>/dev/null', escapeshellarg(
        'require "' . __DIR__ . '/DupBanner.php";'
        . '$b = new Phpdup\\Reporting\\DupBanner();'
        . "echo \$b->frameAtDepth($tick, Phpdup\\Reporting\\DupBanner::DEPTH_TRUECOLOR);"
    )));
    $out1 = $spawn();
    $out2 = $spawn();
    $inproc = $tick === 0 ? $banner->frame(0) : $banner->finalFrame();
    $check($out1 === $out2 && $out1 !== '' && $out1 === $inproc,
        "$label byte-identical across two separate php invocations (and matches in-process)");
}

// ---------------------------------------------------------------- size caps
$strip = static fn (string $s): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\r/', '', $s);
$maxW = 0;
$maxH = 0;
foreach (range(0, $last) as $t) {
    $lines = explode("\n", rtrim($strip($banner->frameAtDepth($t, DupBanner::DEPTH_TRUECOLOR)), "\n"));
    $maxW = max($maxW, (int) max(array_map(static fn (string $l): int => mb_strwidth($l, 'UTF-8'), $lines)));
    $maxH = max($maxH, count($lines));
}
$check($maxH <= 7, "every tick frame <= 7 rows (max seen $maxH)");
$check($maxW <= 56, "every tick frame <= 56 cols (max seen $maxW) -> no overflow at 80 cols");

// ---------------------------------------------------------------- timing budget
$frameCount = count(explode("\e[" . DupBanner::ROWS . "A\r", $banner->stream()));
$check($frameCount <= 45, "tick count <= 45 (stream carries $frameCount frames)");
$check($frameCount * DupBanner::TICK_USEC <= 1_500_000,
    sprintf('one-shot playback %.0f ms <= 1500 ms', $frameCount * DupBanner::TICK_USEC / 1000));

// ---------------------------------------------------------------- stream / hold
$stream = $banner->stream();
$parts = explode("\e[" . DupBanner::ROWS . "A\r", $stream);
$check(str_starts_with($stream, "\e[?25l"), 'stream hides cursor at start');
$check(str_ends_with($stream, "\e[?25h"), 'stream restores cursor at end (one-shot, no loop)');
$partsCopy = $parts;
$check(str_contains((string) end($partsCopy), $banner->finalFrame()), 'stream ends holding the final frame');

// ---------------------------------------------------------------- truecolour frame
$tc = $banner->frame(20);
$check(str_contains($tc, '38;2;') && str_contains($tc, '48;2;'), 'frame carries 24-bit fg + bg SGR');
$check(!preg_match('/\e\[[0-9;]*3[48];5;/', $tc), 'truecolor frame has no 256-colour codes');
$check(substr_count($tc, "\e[0m\n") === 7, 'every row ends with a full SGR reset');
$f44 = $banner->finalFrame();
foreach ([
    '38;2;245;158;11' => 'p-family amber #F59E0B',
    '38;2;102;107;113' => 'dimmed singleton (twinkled #CBD5E1 at hold)',
    '48;2;2;6;23' => 'sky top #020617',
    '48;2;15;23;42' => 'sky bottom #0F172A',
] as $needle => $name) {
    // twinkle/fade/flash scale hues per tick — the pure form must appear in the union window or the hold frame
    $unionMid = $banner->frame(20);
    $check(str_contains($f44, $needle) || str_contains($unionMid, $needle) || str_contains($banner->frame(15), $needle),
        "SGR $needle ($name) appears verbatim in a real frame");
}

// ---------------------------------------------------------------- xterm-256 fallback
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
$check($ok256, 'all ' . $frameCount . ' xterm256 frames use only valid 38;5;N / 48;5;N / reset SGR', $badSeq);

// ---------------------------------------------------------------- plain fallback
$okPlain = true;
foreach ([0, 15, 30, $last] as $t) {
    if (str_contains($banner->frameAtDepth($t, DupBanner::DEPTH_PLAIN), "\e")) {
        $okPlain = false;
    }
}
$check($okPlain, 'plain frames contain ZERO escape codes');
$check($banner->frameAtDepth(0, DupBanner::DEPTH_PLAIN) === $banner->plain(), 'plain frame is static (tick 0 == plain())');
$check($banner->stream(DupBanner::DEPTH_PLAIN) === $banner->plain(), 'plain stream == static plain text');

// ================================================================ union-find truth
// Independent re-implementation (deliberately separate code path): replay the
// public data through our own path-halving union-find with the public order.
$find = static function (int $x, array &$parent): int {
    while ($parent[$x] !== $x) {
        $parent[$x] = $parent[$parent[$x]];
        $x = $parent[$x];
    }

    return $x;
};

$replayRoots = static function (int $tick) use ($find): array {
    $parent = [];
    $rank = [];
    foreach (DupBanner::STARS as $id => $_) {
        $parent[$id] = $id;
        $rank[$id] = 0;
    }
    foreach (DupBanner::activationOrder() as $ei) {
        if (DupBanner::activationTick($ei) > $tick) {
            break;
        }
        [$a, $b] = DupBanner::EDGE_PAIRS[$ei];
        $ra = $find($a, $parent);
        $rb = $find($b, $parent);
        if ($ra === $rb) {
            // cycle-closing edge: the banner runs a compression pass over the component
            foreach (array_keys($parent) as $id) {
                if ($find((int) $id, $parent) === $ra) {
                    $find((int) $id, $parent);
                }
            }
            continue;
        }
        if ($rank[$ra] < $rank[$rb]) {
            $parent[$ra] = $rb;
        } elseif ($rank[$rb] < $rank[$ra]) {
            $parent[$rb] = $ra;
        } else {
            $parent[$rb] = $ra;
            $rank[$ra]++;
        }
    }

    return $parent;
};

$finalParent = $replayRoots($last);
$finalHuesOk = true;
$hueMismatches = '';
foreach (DupBanner::STARS as $id => $star) {
    $root = $find($id, $finalParent);
    $want = DupBanner::STARS[$root]['hue'];
    if ($banner->rootHueAt($last, $id) !== $want) {
        $finalHuesOk = false;
        $hueMismatches .= "star $id root $root ";
    }
}
$check($finalHuesOk, 'final frame: every star displays the hue of its computed union-find root', $hueMismatches);

$midOk = true;
foreach ([15, 20, 26, 31] as $t) {
    $par = $replayRoots($t);
    foreach (DupBanner::STARS as $id => $_) {
        $root = $find($id, $par);
        if ($banner->rootHueAt($t, $id) !== DupBanner::STARS[$root]['hue']) {
            $midOk = false;
        }
    }
}
$check($midOk, 'mid-animation (ticks 15/20/26/31): displayed hues still equal computed root hues');

// clusters: one component per letter, decoys never join, p-family stays 3 distinct clusters
$rootOf = [];
foreach (DupBanner::STARS as $id => $star) {
    $rootOf[$id] = $find($id, $finalParent);
}
$letterOk = true;
foreach (DupBanner::STARS as $id => $s1) {
    foreach (DupBanner::STARS as $jid => $s2) {
        if ($s1['singleton'] || $s2['singleton']) {
            continue; // singletons covered by the dedicated check below
        }
        $sameLetter = $s1['letter'] === $s2['letter'];
        if ($sameLetter !== ($rootOf[$id] === $rootOf[$jid])) {
            $letterOk = false;
        }
    }
}
$decoysAlone = true;
foreach (DupBanner::STARS as $id => $star) {
    if ($star['singleton'] && $rootOf[$id] !== $id) {
        $decoysAlone = false;
    }
}
$check($letterOk && $decoysAlone, 'exactly one component per wordmark letter; no cross-letter unions; decoys stay singletons');

$amber = [245, 158, 11];
$pIds = [0, 1, 2, 3, 4, 5, 11, 12, 13, 14, 15, 16, 27, 28, 29, 30, 31, 32];
$familyOk = true;
foreach ($pIds as $id) {
    if ($banner->rootHueAt($last, $id) !== $amber) {
        $familyOk = false;
    }
}
$pRoots = [$find(0, $finalParent), $find(11, $finalParent), $find(27, $finalParent)];
$check($familyOk, 'the three p letters SHARE one hue (#F59E0B) — duplicate family of three');
$check(count(array_unique($pRoots)) === 3, '…as three DISTINCT clusters (same shape, one hue)', implode('/', $pRoots));

// ---------------------------------------------------------------- threshold gate is real
$allAbove = true;
foreach (array_keys(DupBanner::EDGE_PAIRS) as $i) {
    if (DupBanner::edgeScore($i) <= DupBanner::EDGE_THRESHOLD) {
        $allAbove = false;
    }
}
$check($allAbove, 'every lit edge scores above the 0.80 threshold');
$check(DupBanner::nearMissScore() < DupBanner::EDGE_THRESHOLD, 'near-miss pair (2,5) scores below the gate (' . DupBanner::nearMissScore() . ')');
$pairExists = static function (int $u, int $v): bool {
    foreach (DupBanner::EDGE_PAIRS as $p) {
        if ($p[0] === $u && $p[1] === $v) {
            return true;
        }
    }

    return false;
};
$check(!$pairExists(2, 5) && !$pairExists(5, 2),
    'the below-threshold pair never appears in the edge list (neither direction)');

// ---------------------------------------------------------------- unions + path compression
$events = $banner->flashEvents();
$expectedUnions = count($wordmark) - 6; // 33 vertices merging into 6 letter clusters
$check(count($events) >= $expectedUnions && count($events) <= $expectedUnions + 4,
    "27 true re-rootings + up to 4 cycle compressions (got " . count($events) . ' events)');

$sparks = $banner->sparkEvents();
$check(count($sparks) >= 2, 'path compression shown in the open at least twice (sparks: ' . count($sparks) . ')');
$sparkInWindow = true;
foreach ($sparks as $s) {
    if ($s['tick'] < DupBanner::UNION_START || $s['tick'] > DupBanner::UNION_END) {
        $sparkInWindow = false;
    }
}
$check($sparkInWindow, 'every spark lands inside the 0.4-1.1 s union window');

$rootHueIsRoot = true; // spark target must literally be a root star after the event
foreach ($sparks as $s) {
    if ($find($s['to'], $finalParent) !== $s['to']) {
        $rootHueIsRoot = false;
    }
}
$check($rootHueIsRoot, 'every spark re-points straight to a FINAL root star (root of its letter)');

// activation window
$order = DupBanner::activationOrder();
$ticks = array_map(static fn (int $i): int => DupBanner::activationTick($i), $order);
$check(min($ticks) === DupBanner::UNION_START && max($ticks) === DupBanner::UNION_END,
    'edges fire inside ticks 12..32 (0.4s-1.1s)');
$mono = true;
$scoreDown = true;
foreach ($order as $pos => $ei) {
    if ($pos > 0 && ($ticks[$pos] < $ticks[$pos - 1] || DupBanner::edgeScore($ei) > DupBanner::edgeScore($order[$pos - 1]))) {
        $mono = false;
        $scoreDown = false;
    }
}
$check($mono && $scoreDown, 'union order is the seeded score order (monotone ticks, non-increasing scores)');

// ---------------------------------------------------------------- choreography
$ink = static fn (string $f): int => (int) preg_match_all('/[⠁-⿿]/u', $strip($f));
$check($ink($banner->frame(0)) > 0 && $ink($banner->frame(0)) < $ink($banner->frame(11)),
    'stars fade in: tick 0 shows fewer lit cells than tick 11');
$check($ink($banner->frame(11)) === $ink($banner->frame(12)) || $ink($banner->frame(12)) > $ink($banner->frame(11)),
    'constellation complete by the start of the union phase');
$check($ink($banner->frame(DupBanner::BRIGHT_START)) <= $ink($banner->finalFrame()), 'final frame holds full glyph field');

// ---------------------------------------------------------------- no clock / no unseeded rand
$src = (string) file_get_contents(__DIR__ . '/DupBanner.php');
$check(preg_match('/\b(microtime|hrtime|date|gmdate|localtime|uniqid|srand|mt_srand|rand|mt_rand|random_int|shuffle|array_rand)\s*\(/', $src) === 0,
    'source contains no clock or unseeded randomness primitives');
$check(preg_match_all('/\busleep\s*\(/', $src) === 1, 'single usleep — inside play() pacing only');
$check(preg_match('/function play.*usleep/s', $src) === 1, 'the one usleep belongs to play()');

echo $fail === 0 ? "\nALL CHECKS PASS\n" : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
