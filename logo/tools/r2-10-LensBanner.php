<?php

declare(strict_types=1);

/**
 * LensBanner — "Lens over Repeated Code" animated truecolor ANSI banner for phpdup.
 *
 * Concept: a field of repeated pseudo-PHP lines fills a 56x7 box. A circular
 * lens drifts across it in a serpentine sweep; wherever it touches a duplicate
 * family, that whole family lights up in its own hue (five colourblind-safe
 * cluster hues, one per family). When the lens settles at centre, the code
 * dims and the PHPDUP wordmark appears exactly where the lens centered.
 *
 * Hard constraints honoured (owner spec, round 2):
 *  - one-shot animation, 45 ticks at 30 fps = 1.500 s, then HOLD final frame
 *    (concept's 1.5 s drift + 0.5 s reveal compressed 3:1 -> 1.133 s + 0.367 s)
 *  - 24-bit SGR (38;2/48;2) with 256-colour (38;5/48;5) and plain fallbacks
 *  - final logo <= 7 rows x <= 56 cols
 *  - pure, deterministic frame(int $tick): string — no wall-clock, no global
 *    RNG (texture comes from a position hash: "seeded randomness" by design)
 *  - PHP 8.1 floor, strict types, final class
 *
 * Ties to: src/Clustering/Cluster.php (duplicate families) and
 * src/Reporting/ClusterSort.php (cluster ordering). CLUSTER_PALETTE below is
 * meant to be shared with any cluster-coloured CLI surface.
 */
final class LensBanner
{
    /** Box geometry: the logo is exactly ROWS x COLS. */
    public const COLS = 56;
    public const ROWS = 7;

    /** Playback budget: 45 ticks @ <=30 fps (33,333 us) = 1.4966 s, then hold. */
    public const TICKS = 45;
    public const LAST_TICK = 44;
    public const TICK_US = 33333;

    /**
     * The five cluster hues (colourblind-safe set from the owner brief),
     * ordered exactly as the lens lights them. A cluster-coloured list can
     * share this constant instead of re-spelling hex codes.
     *
     * @var array<int, array{0:int,1:int,2:int}>
     */
    public const CLUSTER_PALETTE = [
        0 => [59, 130, 246],   // #3B82F6 blue
        1 => [245, 158, 11],   // #F59E0B amber
        2 => [16, 185, 129],   // #10B981 emerald
        3 => [236, 72, 153],   // #EC4899 pink
        4 => [139, 92, 246],   // #8B5CF6 violet
    ];

    /** Concept-required dimmed-code colour for the final hold frame. */
    public const DIM_CODE = [71, 85, 105];      // #475569

    /** Depth tokens for frameAt()/render()/play(). */
    public const DEPTH_TRUECOLOR = 'tc';
    public const DEPTH_256 = '256';
    public const DEPTH_PLAIN = 'plain';

    private const LENS_SETTLE = 33;            // last drift tick
    private const RAMP_TICKS = 6.0;            // family hue ramp length
    private const RX = 4.0;                    // lens radii (cells; ~1.9:1 = round)
    private const RY = 1.6;
    private const HOT = 0.45;                  // dn below this = hot core

    private const PAGE = [11, 14, 20];         // box background
    private const NEUTRAL = [134, 145, 162];   // unlit code ink
    private const GLASS = [191, 204, 227];     // lens ring
    private const TINT = [56, 189, 248];       // lens glass tint
    private const WHITE = [255, 255, 255];
    private const HALO = [37, 27, 76];         // wordmark aura (mixed by distance)

    /** Serpentine zigzag path: sweeps left->right while swinging vertically,
        then settles at centre (28,3) where the wordmark later appears. */
    private const KEY_TICKS = [0.0, 6.0, 12.0, 17.0, 21.0, 33.0];
    private const KEY_X = [6.0, 16.0, 26.0, 36.0, 46.0, 28.0];
    private const KEY_Y = [1.0, 5.0, 1.0, 5.0, 2.0, 3.0];

    /** Wordmark: 3x5 block font, centred on the lens settle point (28,3). */
    private const WORD_X = 17;
    private const WORD_ROW = 1;
    private const WORD_COL0 = 16;              // halo box cols 16..40, rows 1..5
    private const WORD_COL1 = 40;
    private const WORD_ROW0 = 1;
    private const WORD_ROW1 = 5;

    /** Glyph masks are 5 rows, strings 1-based (index 0 unused). */
    private const FONT = [
        'P' => ['', '###', '# #', '## ', '#  ', '#  '],
        'H' => ['', '# #', '# #', '###', '# #', '# #'],
        'D' => ['', '## ', '# #', '# #', '# #', '## '],
        'U' => ['', '# #', '# #', '# #', '# #', '.#.'],
    ];
    private const WORD = ['P', 'H', 'P', 'D', 'U', 'P'];

    /** @var array<int, list<array{0:int,1:int}>>|null row => [frag, col] placements */
    private static ?array $fieldRows = null;

    /** @var array<int, string>|null fragment index => text */
    private static ?array $frags = null;

    /** @var list<array{0:int,1:int,2:int,3:string}>|null flat [col,row,family,glyph] */
    private static ?array $flat = null;

    /** @var array<int, int>|null family => first lens-touch tick (-1 = never) */
    private static ?array $triggers = null;

    // ====================================================== pure frame API

    /**
     * One animation tick as a truecolor ANSI frame (ROWS lines, each ending
     * "\e[0m\n"). Pure and deterministic: identical bytes for identical ticks,
     * every process, every run. No wall-clock, no global RNG.
     */
    public static function frame(int $tick): string
    {
        return self::frameAt($tick, self::DEPTH_TRUECOLOR);
    }

    /**
     * Frame at an explicit depth: 'tc' truecolor, '256' xterm-256. 'plain'
     * returns the zero-escape fallback frame regardless of tick (its job is
     * the NO_COLOR / --no-ansi / dumb-terminal static).
     */
    public static function frameAt(int $tick, string $depth = self::DEPTH_TRUECOLOR): string
    {
        if ($tick < 0 || $tick > self::LAST_TICK) {
            throw new \InvalidArgumentException('tick ' . $tick . ' outside 0..' . self::LAST_TICK);
        }
        if ($depth === self::DEPTH_PLAIN) {
            return self::plain();
        }
        if (!in_array($depth, [self::DEPTH_TRUECOLOR, self::DEPTH_256], true)) {
            throw new \InvalidArgumentException("unknown depth '{$depth}'");
        }
        return self::encode(self::cells($tick), $depth);
    }

    /** The final HOLD frame (static logo: dimmed code + lit wordmark). */
    public static function finalFrame(string $depth = self::DEPTH_TRUECOLOR): string
    {
        return self::frameAt(self::LAST_TICK, $depth);
    }

    /**
     * Full raw byte stream: 44 moving frames, each rewound over the previous
     * via cursor-up, ending on the static hold. Zero sleeps — pipe into a
     * terminal raw, or call play() for real 30 fps pacing.
     */
    public static function render(string $depth = self::DEPTH_TRUECOLOR): string
    {
        if ($depth === self::DEPTH_PLAIN) {
            return self::plain();
        }
        $anim = '';
        for ($t = 0; $t < self::LAST_TICK; $t++) {
            $anim .= self::frameAt($t, $depth) . "\e[" . self::ROWS . 'A' . "\r";
        }
        return "\e[?25l" . $anim . self::finalFrame($depth) . "\e[?25h";
    }

    /**
     * Plain-text fallback frame: the logo intent with ZERO escape codes.
     * <= 7 rows x <= 56 cols, ASCII only (safe on dumb terminals).
     */
    public static function plain(): string
    {
        $line = static function (string $text): string {
            $text = substr($text, 0, self::COLS);
            return $text . str_repeat(' ', self::COLS - strlen($text)) . "\n";
        };
        $codeA = '  $x = hash($a);  $o->save();  if ($d > .9) {';
        $codeB = '  $dup = $pairs->all();  clone $item;';
        return ''
            . $line($codeA)
            . $line($codeB)
            . $line('')
            . $line('                        PHPDUP')
            . $line('                        ------')
            . $line('')
            . $line($codeA);
    }

    /**
     * Echo the animation at 30 fps pacing and HOLD the final frame (no
     * trailing newline). Depth auto-detected from env unless passed.
     *
     * @psalm-api public entry point for any host that wants paced playback.
     */
    public static function play(?string $depth = null): void
    {
        $depth = $depth ?? self::detectDepth();
        if ($depth === self::DEPTH_PLAIN) {
            echo self::plain();
            return;
        }
        echo "\e[?25l";
        for ($t = 0; $t < self::TICKS; $t++) {
            echo self::frameAt($t, $depth);
            if ($t < self::LAST_TICK) {
                usleep(self::TICK_US);
                echo "\e[" . self::ROWS . 'A' . "\r";
            }
        }
        echo "\e[?25h";
    }

    /**
     * Depth from environment: NO_COLOR set or TERM empty/dumb -> plain,
     * COLORTERM truecolor|24bit -> tc, otherwise 256.
     */
    public static function detectDepth(): string
    {
        $env = static fn (string $k): string => (string) (getenv($k) !== false ? getenv($k) : '');
        if ($env('NO_COLOR') !== '') {
            return self::DEPTH_PLAIN;
        }
        $term = $env('TERM');
        if ($term === '' || $term === 'dumb') {
            return self::DEPTH_PLAIN;
        }
        $cc = $env('COLORTERM');
        if (str_contains($cc, 'truecolor') || str_contains($cc, '24bit')) {
            return self::DEPTH_TRUECOLOR;
        }
        return self::DEPTH_256;
    }

    // ====================================================== frame building

    /**
     * The tick's cell grid: ROWS lists of COLS [glyph, fg rgb, bg rgb] cells.
     * Public because the PNG/GIF rasteriser consumes grids directly; text
     * frames go through frameAt().
     *
     * @return list<list<array{0:string,1:array{0:int,1:int,2:int},2:array{0:int,1:int,2:int}}>>
     */
    public static function cells(int $tick): array
    {
        $grid = self::blankGrid();
        $dim = self::clamp01(($tick - 34) / 8);
        $ringA = $tick < 34 ? 1.0 : max(0.0, 1 - ($tick - 34) / 3);
        [$cx, $cy] = self::pathCenter($tick);

        // ---- code field ----------------------------------------------------
        foreach (self::field() as [$x, $y, $fam, $ch]) {
            $dn = (($x - $cx) * ($x - $cx)) / (self::RX * self::RX)
                + (($y - $cy) * ($y - $cy)) / (self::RY * self::RY);
            if ($fam >= 0) {
                $p = self::litProgress($fam, $tick);
                $col = self::mix(self::NEUTRAL, self::CLUSTER_PALETTE[$fam % 5], $p);
                if ($dn < 1.0) {
                    $col = self::mix($col, self::WHITE, 0.18 * (1 - $dn));
                }
                if ($dn < self::HOT) {
                    $col = self::mix($col, self::WHITE, 0.5);
                }
            } else {
                $col = self::NEUTRAL;
                if ($dn < 1.0) {
                    $col = self::mix($col, self::TINT, 0.14 * (1 - $dn));
                }
            }
            if ($dim > 0.0) {
                $col = self::mix($col, self::DIM_CODE, $dim);
            }
            $col = self::scale($col, 0.88 + 0.15 * self::noise($x, $y, 7));
            $grid[$y][$x] = [$ch, $col, self::PAGE];
        }

        // ---- glass tint on empty interior cells ----------------------------
        if ($ringA > 0.5) {
            $y0 = max(0, (int) floor($cy - 2));
            $y1 = min(self::ROWS - 1, (int) ceil($cy + 2));
            for ($y = $y0; $y <= $y1; $y++) {
                for ($x = 0; $x < self::COLS; $x++) {
                    $dn = (($x - $cx) * ($x - $cx)) / (self::RX * self::RX)
                        + (($y - $cy) * ($y - $cy)) / (self::RY * self::RY);
                    if ($dn < 0.9 && $grid[$y][$x][0] === ' ') {
                        $grid[$y][$x][2] = self::mix(self::PAGE, self::TINT, 0.07 * (1 - $dn));
                    }
                }
            }
        }

        // ---- phase B: halo wipe + wordmark + rule + tagline ----------------
        if ($dim > 0.0) {
            $haloA = self::clamp01(($tick - 36) / 4);
            for ($y = self::WORD_ROW0; $y <= self::WORD_ROW1; $y++) {
                $dv = $y <= self::WORD_ROW0 ? 1.0 : ($y >= self::WORD_ROW1 ? 1.0 : 0.0);
                for ($x = self::WORD_COL0; $x <= self::WORD_COL1; $x++) {
                    $dx = max(0.0, (abs($x - 28) - 11) / 1.0);
                    $e = min(1.0, $dx * $dx + $dv * $dv);
                    $grid[$y][$x] = [' ', self::NEUTRAL,
                        self::mix($grid[$y][$x][2], self::HALO, 0.85 * $haloA * $e)];
                }
            }
            $w = ($tick - 35) / 8.5;
            foreach (self::WORD as $i => $letter) {
                $alpha = self::clamp01(($w - $i * 0.16) / 0.3);
                if ($alpha <= 0.0) {
                    continue;
                }
                $hue = self::CLUSTER_PALETTE[$i % 5];
                $col = self::mix(self::PAGE, $hue, $alpha);
                if ($alpha >= 1.0) {
                    $col = self::mix($col, self::WHITE, 0.08);
                }
                foreach (self::FONT[$letter] as $row1 => $mask) {
                    if ($row1 === 0 || $mask === '') {
                        continue;
                    }
                    foreach (str_split($mask) as $dxc => $bit) {
                        if ($bit !== '#') {
                            continue;
                        }
                        $grid[self::WORD_ROW + $row1 - 1][self::WORD_X + $i * 4 + $dxc] = ['█', $col, self::PAGE];
                    }
                }
            }
            $ta = self::clamp01(($tick - 37) / 4);
            if ($ta > 0.0) {
                for ($x = 17; $x <= 39; $x++) {
                    $t = ($x - 17) / 22;
                    $c1 = self::mix(self::CLUSTER_PALETTE[0], self::CLUSTER_PALETTE[4], min(1.0, $t * 2));
                    $c2 = self::mix($c1, self::CLUSTER_PALETTE[3], max(0.0, ($t - 0.5) * 2));
                    $grid[6][$x] = ['─', self::mix(self::PAGE, $c2, $ta), self::PAGE];
                }
            }
            $tb = self::clamp01(($tick - 39) / 3.5);
            if ($tb > 0.0) {
                $tag = '· dupes ·';
                $tagX = 28 - intdiv(mb_strwidth($tag, 'UTF-8'), 2);
                foreach (mb_str_split($tag) as $j => $g) {
                    $grid[0][$tagX + $j] = [$g, self::mix(self::PAGE, self::CLUSTER_PALETTE[1], $tb), self::PAGE];
                }
            }
        }

        // ---- lens ring (drawn last: settles over the rising wordmark) ------
        if ($ringA > 0.01) {
            foreach (self::ringPoints($cx, $cy) as [$x, $y, $g]) {
                if ($x < 0 || $x >= self::COLS || $y < 0 || $y >= self::ROWS) {
                    continue;
                }
                $grid[$y][$x] = [$g,
                    self::mix($grid[$y][$x][1], self::GLASS, $ringA),
                    self::mix($grid[$y][$x][2], [20, 26, 44], 0.6 * $ringA)];
            }
        }
        return $grid;
    }

    /**
     * Lens centre at a (fractional-OK) tick: eased piecewise-linear serpentine.
     *
     * @return array{0:float,1:float}
     */
    public static function pathCenter(float $tick): array
    {
        if ($tick >= (float) self::LENS_SETTLE) {
            return [self::KEY_X[5], self::KEY_Y[5]];
        }
        // 6 keyframes -> last index 5 (stays in sync with KEY_TICKS/KEY_X/KEY_Y)
        $seg = 0;
        while ($seg + 1 < count(self::KEY_TICKS) && $tick >= self::KEY_TICKS[min(5, $seg + 1)]) {
            $seg++;
        }
        // clamp into the provable segment range (6 keyframes -> segs 0..4; loop already ensures it)
        $seg = max(0, min(4, $seg));
        $nxt = $seg + 1;
        $span = self::KEY_TICKS[$nxt] - self::KEY_TICKS[$seg];
        $u = $span > 0.0 ? self::ease(($tick - self::KEY_TICKS[$seg]) / $span) : 1.0;
        return [
            self::KEY_X[$seg] + (self::KEY_X[$nxt] - self::KEY_X[$seg]) * $u,
            self::KEY_Y[$seg] + (self::KEY_Y[$nxt] - self::KEY_Y[$seg]) * $u,
        ];
    }

    /**
     * Hue-ramp progress (0..1) for a family: starts the tick the lens first
     * touches any of its cells, then ramps over RAMP_TICKS.
     */
    public static function litProgress(int $fam, int $tick): float
    {
        $tr = self::triggerTick($fam);
        return $tr < 0 ? 0.0 : self::clamp01(($tick - $tr) / self::RAMP_TICKS);
    }

    // ====================================================== field layout

    /**
     * Field rows: explicit [fragment, startCol] placements. Primary windows of
     * families 0..4 were selected against the true coverage map so the lens
     * first touches them at ticks 2, 6, 8, 14, 19 (palette order); each family
     * gets a clone in a late or never-lit window, distant duplicates that
     * light "at a distance" with their family.
     *
     * @return array<int, list<array{0:int,1:int}>>
     */
    private static function fieldRows(): array
    {
        if (self::$fieldRows === null) {
            /** @var array<int, list<array{0:int,1:int}>> $rows */
            $rows = [
                0 => [[7, 0], [2, 14], [16, 37], [11, 40], [5, 52]],
                1 => [[8, 0], [10, 9], [13, 17], [4, 34], [16, 49], [15, 52]],
                2 => [[6, 0], [15, 3], [0, 10], [3, 30], [12, 43], [16, 54]],
                3 => [[10, 0], [16, 8], [2, 10], [4, 34], [12, 49]],
                4 => [[24, 0], [3, 24], [25, 37], [1, 42], [16, 54]],
                5 => [[16, 0], [9, 3], [1, 20], [18, 33], [17, 46]],
                6 => [[24, 0], [25, 26], [16, 29], [0, 41], [27, 55]],
            ];
            self::$fieldRows = $rows;
        }
        return self::$fieldRows;
    }

    /**
     * Pseudo-PHP fragments 0..4 = duplicate families (one hue each, tiled
     * across the field); 5..27 = filler.
     *
     * @return array<int, string>
     */
    private static function frags(): array
    {
        if (self::$frags === null) {
            self::$frags = [
                0 => '$x = hash($a);',
                1 => 'return $r;',
                2 => 'foreach ($q as $s) {',
                3 => '$o->save();',
                4 => 'if ($d > .9) {',
                5 => '$m = $n * 2;',
                6 => '}',
                7 => 'yield $v;',
                8 => '// note',
                9 => 'new Set($k);',
                10 => 'break;',
                11 => '$p = $q[0];',
                12 => 'continue;',
                13 => 'use App\Q;',
                14 => 'clone $item;',
                15 => 'try {',
                16 => '}',
                17 => 'catch (E $e) {',
                18 => 'throw $e;',
                19 => '$dup = $pairs->all();',
                20 => 'return $c->impact;',
                21 => 'usort($items, $fn);',
                22 => '}',
                23 => 'fn($x) => $x;',
                24 => 'public function run($in)',
                25 => '{',
                26 => '$log->write($m);',
                27 => ';',
            ];
        }
        return self::$frags;
    }

    /**
     * Flat list of every painted glyph cell: [col, row, family(-1=filler), glyph].
     *
     * @return list<array{0:int,1:int,2:int,3:string}>
     */
    private static function field(): array
    {
        if (self::$flat !== null) {
            return self::$flat;
        }
        $out = [];
        foreach (self::fieldRows() as $y => $placements) {
            foreach ($placements as [$fi, $col]) {
                foreach (str_split(self::frags()[$fi]) as $j => $ch) {
                    $c = $col + $j;
                    if ($c < self::COLS) {
                        $out[] = [$c, $y, $fi < 5 ? $fi : -1, $ch];
                    }
                }
            }
        }
        return self::$flat = $out;
    }

    /** First tick at which the lens touches each family's cells (computed once). */
    private static function triggerTick(int $fam): int
    {
        if (self::$triggers === null) {
            $map = [];
            foreach (array_keys(self::CLUSTER_PALETTE) as $f) {
                $map[$f] = -1;
            }
            for ($t = 0; $t <= self::LAST_TICK; $t++) {
                [$cx, $cy] = self::pathCenter($t);
                foreach (self::field() as [$x, $y, $fam2]) {
                    if ($fam2 < 0 || isset($map[$fam2]) && $map[$fam2] >= 0) {
                        continue;
                    }
                    $dn = (($x - $cx) * ($x - $cx)) / (self::RX * self::RX)
                        + (($y - $cy) * ($y - $cy)) / (self::RY * self::RY);
                    if ($dn <= 1.0) {
                        $map[$fam2] = $t;
                    }
                }
            }
            self::$triggers = $map;
        }
        return self::$triggers[$fam] ?? -1;
    }

    /** @return list<array{0:int,1:int,2:string}> ring cells [x,y,glyph] */
    private static function ringPoints(float $cx, float $cy): array
    {
        $pts = [];
        for ($dx = -4; $dx <= 4; $dx++) {
            if ($dx === 0) {
                continue;
            }
            $inside = 1 - ($dx * $dx) / (self::RX * self::RX);
            if ($inside <= 0.0) {
                continue;
            }
            $off = self::RY * sqrt($inside);
            $x = (int) round($cx + $dx);
            if ($off < 0.85) {
                $pts[] = [$x, (int) round($cy), $dx < 0 ? '(' : ')'];
                continue;
            }
            $yt = (int) round($cy - $off);
            $yb = (int) round($cy + $off);
            $gTop = $dx < 0 ? '╭' : '╮';
            $gBot = $dx < 0 ? '╰' : '╯';
            if ($yt === $yb) {
                $pts[] = [$x, $yt, '─'];
            } else {
                $pts[] = [$x, $yt, $gTop];
                $pts[] = [$x, $yb, $gBot];
            }
        }
        // side cells of the ring (the dx loop's ellipse never reaches |dx|=4)
        $pts[] = [(int) round($cx - 4), (int) round($cy), '('];
        $pts[] = [(int) round($cx + 4), (int) round($cy), ')'];
        return $pts;
    }

    // ====================================================== encoding

    /**
     * Encode a cell grid at depth tc|256. SGRs only on state change; every
     * line ends with \e[0m and a newline.
     *
     * @param list<list<array{0:string,1:array{0:int,1:int,2:int},2:array{0:int,1:int,2:int}}>> $cells
     */
    private static function encode(array $cells, string $depth): string
    {
        $out = '';
        foreach ($cells as $row) {
            $curF = '';
            $curB = '';
            $line = '';
            foreach ($row as [$ch, $fg, $bg]) {
                $b = self::sgr($bg, true, $depth);
                if ($b !== $curB) {
                    $line .= "\e[{$b}m";
                    $curB = $b;
                }
                if ($ch !== ' ') {
                    $f = self::sgr($fg, false, $depth);
                    if ($f !== $curF) {
                        $line .= "\e[{$f}m";
                        $curF = $f;
                    }
                }
                $line .= $ch;
            }
            $out .= $line . "\e[0m\n";
        }
        return $out;
    }

    /** @param array{0:int,1:int,2:int} $c */
    private static function sgr(array $c, bool $bg, string $depth): string
    {
        if ($depth === self::DEPTH_TRUECOLOR) {
            return ($bg ? '48;2;' : '38;2;') . $c[0] . ';' . $c[1] . ';' . $c[2];
        }
        return ($bg ? '48;5;' : '38;5;') . (string) self::to256($c);
    }

    /**
     * Nearest xterm-256 cube/grey index (redmean-weighted).
     *
     * @param array{0:int,1:int,2:int} $c
     */
    private static function to256(array $c): int
    {
        /** @var array<string, int> $memo */
        static $memo = [];
        $key = $c[0] . ',' . $c[1] . ',' . $c[2];
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $best = 16;
        $bd = INF;
        /** @var list<int> $steps */
        $steps = [0, 95, 135, 175, 215, 255];
        $cap = static fn (int $v): int => max(0, min(5, $v));
        for ($i = 0; $i < 216; $i++) {
            $p = [$steps[$cap(intdiv($i, 36))], $steps[$cap(intdiv($i, 6) % 6)], $steps[$cap($i % 6)]];
            $d = self::dist($c, $p);
            if ($d < $bd) {
                $bd = $d;
                $best = 16 + $i;
            }
        }
        for ($i = 0; $i < 24; $i++) {
            $v = 8 + $i * 10;
            $d = self::dist($c, [$v, $v, $v]);
            if ($d < $bd) {
                $bd = $d;
                $best = 232 + $i;
            }
        }
        return $memo[$key] = $best;
    }

    /** @param array{0:int,1:int,2:int} $a
     *  @param array{0:int,1:int,2:int} $b
     */
    private static function dist(array $a, array $b): float
    {
        $rm = ($a[0] + $b[0]) / 2;
        $dr = $a[0] - $b[0];
        $dg = $a[1] - $b[1];
        $db = $a[2] - $b[2];
        return (2 + $rm / 256) * $dr * $dr + 4 * $dg * $dg + (2 + (255 - $rm) / 256) * $db * $db;
    }

    // ====================================================== colour math

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     * @return array{0:int,1:int,2:int}
     */
    private static function mix(array $a, array $b, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $c
     * @return array{0:int,1:int,2:int}
     */
    private static function scale(array $c, float $k): array
    {
        return array_map(
            static fn (int $v): int => (int) max(0, min(255, round($v * $k))),
            $c,
        );
    }

    /** @return list<list<array{0:string,1:array{0:int,1:int,2:int},2:array{0:int,1:int,2:int}}>> */
    private static function blankGrid(): array
    {
        $cell = [' ', self::NEUTRAL, self::PAGE];
        $grid = [];
        for ($y = 0; $y < self::ROWS; $y++) {
            $grid[] = array_fill(0, self::COLS, $cell);
        }
        return $grid;
    }

    private static function clamp01(float $t): float
    {
        return max(0.0, min(1.0, $t));
    }

    private static function ease(float $t): float
    {
        $t = self::clamp01($t);
        return $t < 0.5 ? 4 * $t * $t * $t : 1 - ((-$t + 1) ** 3) / 2;
    }

    /** Deterministic position hash in [0,1) — the seeded-texture stand-in. */
    private static function noise(int $x, int $y, int $seed): float
    {
        $h = ($x * 374761393 + $y * 668265263 + $seed * 2147483647) & 0xFFFFFFFF;
        $h = (($h ^ ($h >> 13)) * 1274126177) & 0xFFFFFFFF;
        return (float) (($h ^ ($h >> 16)) & 0xFFFFFF) / 0x1000000;
    }
}
