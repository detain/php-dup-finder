<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud gates for the Syntax Tree Growth banner.
 *
 *   php verify.php           in-process assertions (layout, sizes, budgets,
 *                            fallback validity, determinism, and the
 *                            matched-subtree identity proof parsed FROM the
 *                            rendered truecolor bytes)
 *   php verify.php --store   write hashes of tick-0 / final / stream
 *   php verify.php --cross   re-derive in a SEPARATE php run and compare
 *
 * Exit 0 = everything passed; any failure prints FAIL: reason and exits 1.
 */

require __DIR__ . '/TreeGrowthBanner.php';

$mode = $argv[1] ?? '';
$banner = new TreeGrowthBanner(0);
$finalTick = $banner->finalTick();

$failures = [];
/** @param callable():bool $check */
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

/**
 * Parse a truecolor frame into non-space cells: key "row:col" => [glyph, fg].
 *
 * @return array<string, array{glyph: string, fg: ?array{0:int,1:int,2:int}}>
 */
$parseFrame = static function (string $frame): array {
    $cells = [];
    foreach (explode("\n", rtrim($frame, "\n")) as $row => $line) {
        $fg = null;
        $col = 0;
        $split = preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $tokens = $split === false ? [] : $split;
        foreach ($tokens as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m) === 1) {
                $params = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                if ($params === [0]) {
                    $fg = null;
                } elseif (count($params) === 5 && $params[0] === 38 && $params[1] === 2) {
                    $fg = [$params[2], $params[3], $params[4]];
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                if ($g !== ' ') {
                    $cells[$row . ':' . $col] = ['glyph' => $g, 'fg' => $fg];
                }
                $col++;
            }
        }
    }

    return $cells;
};

/** @param list<array{row: int, col: int, glyph: string}> $cells */
$keysOf = static fn (array $cells): array => array_map(
    static fn (array $c): string => $c['row'] . ':' . $c['col'] . ':' . $c['glyph'],
    $cells,
);

$strip = static fn (string $frame): string => preg_replace('/\e\[[0-9;]*m/', '', $frame) ?? $frame;

// ---------------------------------------------------------------- determinism

$tick0 = $banner->frame(0);
$finalFrame = $banner->frame($finalTick);
$stream = $banner->stream();

$assert($tick0 === $banner->frame(0), 'frame(0) not byte-identical when re-rendered');
$assert($finalFrame === $banner->frame($finalTick), 'final frame not byte-identical when re-rendered');
$assert($stream === $banner->stream(), 'stream not byte-identical when re-rendered');
$assert(
    $tick0 === $banner->render(0, TreeGrowthBanner::MODE_TRUECOLOR),
    'frame() and render(truecolor) diverge',
);

$hashFile = __DIR__ . '/.verify-hashes.json';
$hashes = [
    'tick0' => md5($tick0),
    'final' => md5($finalFrame),
    'stream' => md5($stream),
];

if ($mode === '--store') {
    file_put_contents($hashFile, (string) json_encode($hashes, JSON_PRETTY_PRINT) . "\n");
    echo "STORED tick0={$hashes['tick0']} final={$hashes['final']}\n";
    exit(0);
}

if ($mode === '--cross') {
    $stored = json_decode((string) file_get_contents($hashFile), true);
    $assert(is_array($stored), 'no stored hashes to compare against (run --store first)');
    if (is_array($stored)) {
        $assert(($stored['tick0'] ?? '') === $hashes['tick0'], 'tick 0 NOT byte-reproducible across php invocations');
        $assert(($stored['final'] ?? '') === $hashes['final'], 'final frame NOT byte-reproducible across php invocations');
        $assert(($stored['stream'] ?? '') === $hashes['stream'], 'stream NOT byte-reproducible across php invocations');
    }
    finish($failures, "cross-run determinism OK (tick0 {$hashes['tick0']}, final {$hashes['final']})");
}

// ---------------------------------------------------------------- budgets

$assert($banner->frameCount() <= TreeGrowthBanner::MAX_TICKS, 'tick count exceeds 45');
$playbackUs = $finalTick * TreeGrowthBanner::FRAME_INTERVAL_US;
$assert($playbackUs <= 1_500_000, "playback {$playbackUs}us exceeds 1.5 s budget");
// 30 fps cap holds by construction: FRAME_INTERVAL_US = 33333 = 1/30 s.

$lines = explode("\n", rtrim($finalFrame, "\n"));
$assert(count($lines) === TreeGrowthBanner::ROWS, 'final frame is not exactly 7 rows');
foreach ($lines as $i => $line) {
    $width = mb_strwidth($strip($line));
    $assert($width <= TreeGrowthBanner::COLS, "row $i is {$width} cols (> 56)");
}
// (per-row <=56 check above already implies no overflow at 80 cols)

// monotone growth: visible glyph count never decreases
$prev = -1;
for ($tick = 0; $tick <= $finalTick; $tick++) {
    $count = substr_count(str_replace("\n", '', $strip($banner->render($tick, TreeGrowthBanner::MODE_PLAIN))), ' ') ;
    $used = TreeGrowthBanner::ROWS * TreeGrowthBanner::COLS - $count;
    $assert($used >= $prev, "glyph count decreased at tick $tick");
    $prev = $used;
}

// ---------------------------------------------------------------- fallbacks

$plain = $banner->staticPlain();
$assert(!str_contains($plain, "\e"), 'plain fallback contains an escape code');
$assert($banner->stream(TreeGrowthBanner::MODE_PLAIN) === $plain, 'plain stream is not the static frame alone');
$assert(str_contains($plain, 'phpdup'), 'plain frame lost the wordmark');

$frame256 = $banner->render($finalTick, TreeGrowthBanner::MODE_256);
$assert($strip($frame256) === $strip($finalFrame), '256 layout differs from truecolor layout');
$matchCount = preg_match_all('/\e\[[0-9;]*m/', $frame256, $matches);
$codes = $matchCount === false ? 0 : $matchCount;
$assert($codes > 0, '256 frame emitted no SGR codes at all');
foreach ($matches[0] as $code) {
    if ($code === "\e[0m") {
        continue;
    }
    $isIndexCode = preg_match('/^\e\[38;5;(\d{1,3})m$/', $code, $m2) === 1;
    $ok = $isIndexCode && (int) $m2[1] >= 16 && (int) $m2[1] <= 231;
    $assert($ok, '256 frame contains an invalid SGR code: ' . addcslashes($code, "\e"));
}
$assert(!str_contains($frame256, '38;2;'), '256 frame leaked truecolor codes');

foreach ([3, 7] as $probeSeed) {
    $variant = new TreeGrowthBanner($probeSeed);
    $assert(
        !str_contains($variant->render($variant->finalTick(), TreeGrowthBanner::MODE_PLAIN), "\e"),
        "plain fallback leaked escapes for seed $probeSeed",
    );
    $assert($variant->frameCount() <= TreeGrowthBanner::MAX_TICKS, "seed $probeSeed exceeds tick budget");
}

// ---------------------------------------------------------------- out-of-range

$throws = 0;
foreach ([[-1], [$finalTick + 1]] as $bad) {
    try {
        $banner->frame($bad[0]);
    } catch (InvalidArgumentException) {
        $throws++;
    }
}
try {
    $banner->render(0, 'bmp');
} catch (InvalidArgumentException) {
    $throws++;
}
$assert($throws === 3, 'out-of-range tick / bad mode did not all fail loudly');

// ---------------------------------------------------------------- identity proof

$anatomy = $banner->emeraldAnatomyAPI();
$cells = $parseFrame($finalFrame);

$isEmerald = static fn (?array $fg): bool => $fg === TreeGrowthBanner::EMERALD_RGB;

$renderedEmerald = [];
foreach ($cells as $key => $cell) {
    if ($isEmerald($cell['fg'])) {
        $renderedEmerald[] = $key . ':' . $cell['glyph'];
    }
}
sort($renderedEmerald);

$expectedEmerald = array_merge(
    $keysOf($anatomy['left']),
    $keysOf($anatomy['right']),
    $keysOf($anatomy['bridge']),
    $keysOf($anatomy['arch']),
    $keysOf($banner->graftCells()),
);
sort($expectedEmerald);
$assert(
    $expectedEmerald === $renderedEmerald,
    'emerald cells in the rendered frame != claimed anatomy: '
        . (string) json_encode([
            'only-expected' => array_values(array_diff($expectedEmerald, $renderedEmerald)),
            'only-rendered' => array_values(array_diff($renderedEmerald, $expectedEmerald)),
        ]),
);

// the two matched subtrees must be one pattern, TRANSLATED — never mirrored
$offset = $anatomy['offset'];
$assert($offset > 0, 'matched offset must be positive');
$right = [];
foreach ($anatomy['right'] as $cell) {
    $right[] = $cell['row'] . ':' . ($cell['col'] - $offset) . ':' . $cell['glyph'];
}
$left = $keysOf($anatomy['left']);
sort($right);
sort($left);
$assert($left === $right, 'matched subtrees are NOT translation-identical: ' . (string) json_encode([$left, $right]));

// pattern must be non-trivial (a real subtree: node, junctions, bars, leaves)
$distinctRows = [];
foreach ($anatomy['left'] as $cell) {
    $distinctRows[$cell['row']] = true;
}
$assert(count($distinctRows) >= 3, 'matched pattern spans fewer than 3 rows — too trivial to prove anything');

// every identity cell really shows up emerald in the rendered bytes
foreach ($anatomy['left'] as $cell) {
    $key = $cell['row'] . ':' . $cell['col'];
    $assert(isset($cells[$key]) && $cells[$key]['glyph'] === $cell['glyph'], "left identity cell $key missing/wrong glyph");
    $assert($isEmerald($cells[$key]['fg'] ?? null), "left identity cell $key not emerald");
    $rKey = $cell['row'] . ':' . ($cell['col'] + $offset);
    $assert(isset($cells[$rKey]) && $cells[$rKey]['glyph'] === $cell['glyph'], "right identity cell $rKey missing/wrong glyph");
    $assert($isEmerald($cells[$rKey]['fg'] ?? null), "right identity cell $rKey not emerald");
}

// unmatched branches stay slate, trunks/ground stay bark
$slate = static fn (int $row, int $col): bool => ($cells[$row . ':' . $col]['fg'] ?? null) === TreeGrowthBanner::SLATE_RGB;
$bark = static fn (int $row, int $col): bool => ($cells[$row . ':' . $col]['fg'] ?? null) === TreeGrowthBanner::BARK_RGB;
$assert($slate(3, 7) && $slate(3, 49), 'unmatched branch nodes are not slate');
$assert($slate(1, 4) && $slate(1, 52), 'outer leaves are not slate');
$assert($bark(5, 12) && $bark(5, 44), 'roots are not bark');
$assert($bark(6, 25) && $bark(6, 30), 'wordmark is not bark');
$assert($slate(2, 4) && $isEmerald($cells['2:14']['fg'] ?? null), 'row-2 corner colour classes wrong (4 slate, 14 emerald)');

// seed variant keeps the same guarantee with spacing 4 (if this seed lands there)
foreach ([2, 3, 7] as $probeSeed) {
    $variant = new TreeGrowthBanner($probeSeed);
    $vAnatomy = $variant->emeraldAnatomyAPI();
    $vRight = [];
    foreach ($vAnatomy['right'] as $cell) {
        $vRight[] = $cell['row'] . ':' . ($cell['col'] - $vAnatomy['offset']) . ':' . $cell['glyph'];
    }
    $vLeft = $keysOf($vAnatomy['left']);
    sort($vRight);
    sort($vLeft);
    $assert($vLeft === $vRight, "seed $probeSeed matched subtrees not identical");
    $vCells = $parseFrame($variant->frame($variant->finalTick()));
    $vEmerald = [];
    foreach ($vCells as $key => $cell) {
        if ($isEmerald($cell['fg'])) {
            $vEmerald[] = $key . ':' . $cell['glyph'];
        }
    }
    $vExpected = array_merge(
        $keysOf($vAnatomy['left']),
        $keysOf($vAnatomy['right']),
        $keysOf($vAnatomy['bridge']),
        $keysOf($vAnatomy['arch']),
        $keysOf($variant->graftCells()),
    );
    sort($vEmerald);
    sort($vExpected);
    $assert($vExpected === $vEmerald, "seed $probeSeed emerald anatomy mismatch");
}

finish($failures, sprintf(
    "ALL GATES PASS — %d frames, %.2fs playback, hold %dx%d, seed 0 (leaf spacing %d, offset %d)",
    $banner->frameCount(),
    $playbackUs / 1_000_000,
    TreeGrowthBanner::ROWS,
    TreeGrowthBanner::COLS,
    $banner->leafSpacing(),
    $offset,
));

/** @param list<string> $failures */
function finish(array $failures, string $successLine): never
{
    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, "FAIL: $failure\n");
        }
        exit(1);
    }
    echo "OK: $successLine\n";
    exit(0);
}
