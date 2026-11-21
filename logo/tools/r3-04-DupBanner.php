<?php

declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * DupBanner — animated truecolor ANSI logo for phpdup: "Union-Find Constellations" (round-3 concept 4).
 *
 * A night sky of 41 stars, each one a code block. 33 of them sit on the
 * vertices of the wordmark p h p d u p, 8 are dim decoy singletons. Pairs
 * scoring above EDGE_THRESHOLD flicker on as braille lines in seeded
 * score order and run a REAL union-find (path-halving find + union-by-rank,
 * mirroring src/Clustering/Clusterer.php::UnionFind) over the sky:
 *  - every component takes its root star's hue;
 *  - each UNION flashes the dethroned component white-hot;
 *  - a spark line re-points from a member of the moved component straight
 *    to the new root — path compression shown in the open (cycle-closing
 *    edges trigger the same visible find()-pass on their component);
 *  - the three p's are the same shape, so they share one hue (#F59E0B):
 *    a duplicate family of three hidden in the wordmark itself.
 *
 * Timeline — 45 ticks @ 30 fps = 1.5 s, then the final frame holds:
 *   ticks  0-11  stars fade in, seeded stagger (0.4 s)
 *   ticks 12-32  edges flicker on, unions in seeded order (0.4-1.1 s)
 *   ticks 33-43  lines brighten until the letters read clearly (1.1-1.5 s)
 *   tick  44     hold — twinkle stops, the wordmark sits still
 *
 * Canvas: 7 rows x 56 cols, painted as a 112 x 28 braille dot grid.
 * Colour: 38;2/48;2 truecolor, xterm-256 fallback, zero-escape plain frame
 * for undecorated / NO_COLOR / --no-ansi output.
 * frame() is PURE: the tick plus SEED are its only inputs — no wall clock.
 *
 * @phpstan-type Rgb array{0: int, 1: int, 2: int}
 * @phpstan-type DotGrid array{color: array<int, array<int, Rgb>>, prio: array<int, array<int, int>>}
 */
final class DupBanner
{
    public const DEPTH_TRUECOLOR = 'truecolor';
    public const DEPTH_256 = 'xterm256';
    public const DEPTH_PLAIN = 'plain';

    public const COLS = 56;
    public const ROWS = 7;
    public const DOT_W = 112;   // 56 cells x 2 dot columns
    public const DOT_H = 28;    //  7 cells x 4 dot rows

    public const FPS = 30;
    public const TICK_COUNT = 45;
    public const LAST_TICK = self::TICK_COUNT - 1;
    public const TICK_USEC = 33_333;

    public const UNION_START = 12;              // 0.4 s — first edge fires
    public const UNION_END = 32;                // 1.1 s — last edge fires
    public const BRIGHT_START = 33;             // line-brightening ramp begins
    public const FLASH_SPAN = 3;                // ticks a union flash / spark stays visible

    public const SEED = 0x7A11F04;
    public const EDGE_THRESHOLD = 0.80;

    private const SKY_TOP = [2, 6, 23];          // #020617
    private const SKY_BOTTOM = [15, 23, 42];     // #0F172A
    private const ACCENT = [139, 92, 246];       // #8B5CF6 — compression spark
    private const WHITE = [255, 255, 255];

    /**
     * Star table (id = index). Letters p/h/d/u/p/p in wordmark order, then 8
     * decoy singletons. The three p letters (ids 0-5, 11-16, 27-32) all carry
     * the exact same #F59E0B personal hue — whichever star roots each letter
     * component, the whole p-family can only ever display one hue: the
     * duplicate-family rule is baked into the data.
     *
     * @var list<array{x: int, y: int, letter: string, hue: array{0: int, 1: int, 2: int}, singleton: bool}>
     */
    public const STARS = [
        // ---- p  (origin x=8): stem (8,11/18/24) + bowl (16,11)-(19,15)-(16,18)
        ['x' => 8, 'y' => 11, 'letter' => 'p0', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 8, 'y' => 18, 'letter' => 'p0', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 8, 'y' => 24, 'letter' => 'p0', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 16, 'y' => 11, 'letter' => 'p0', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 19, 'y' => 15, 'letter' => 'p0', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 16, 'y' => 18, 'letter' => 'p0', 'hue' => [245, 158, 11], 'singleton' => false],
        // ---- h  (origin x=24) — blue tones, hue collapses visibly per union
        ['x' => 24, 'y' => 4, 'letter' => 'h', 'hue' => [59, 130, 246], 'singleton' => false],
        ['x' => 24, 'y' => 11, 'letter' => 'h', 'hue' => [96, 165, 250], 'singleton' => false],
        ['x' => 24, 'y' => 18, 'letter' => 'h', 'hue' => [37, 99, 235], 'singleton' => false],
        ['x' => 35, 'y' => 11, 'letter' => 'h', 'hue' => [147, 197, 253], 'singleton' => false],
        ['x' => 35, 'y' => 18, 'letter' => 'h', 'hue' => [29, 78, 216], 'singleton' => false],
        // ---- p  (origin x=40)
        ['x' => 40, 'y' => 11, 'letter' => 'p1', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 40, 'y' => 18, 'letter' => 'p1', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 40, 'y' => 24, 'letter' => 'p1', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 48, 'y' => 11, 'letter' => 'p1', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 51, 'y' => 15, 'letter' => 'p1', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 48, 'y' => 18, 'letter' => 'p1', 'hue' => [245, 158, 11], 'singleton' => false],
        // ---- d  (origin x=56, stem at x=67) — emerald tones
        ['x' => 67, 'y' => 4, 'letter' => 'd', 'hue' => [16, 185, 129], 'singleton' => false],
        ['x' => 67, 'y' => 11, 'letter' => 'd', 'hue' => [52, 211, 153], 'singleton' => false],
        ['x' => 67, 'y' => 18, 'letter' => 'd', 'hue' => [13, 148, 136], 'singleton' => false],
        ['x' => 60, 'y' => 10, 'letter' => 'd', 'hue' => [110, 231, 183], 'singleton' => false],
        ['x' => 56, 'y' => 14, 'letter' => 'd', 'hue' => [5, 150, 105], 'singleton' => false],
        ['x' => 60, 'y' => 18, 'letter' => 'd', 'hue' => [15, 118, 110], 'singleton' => false],
        // ---- u  (origin x=72) — pink tones (square cup sitting on the baseline)
        ['x' => 72, 'y' => 11, 'letter' => 'u', 'hue' => [236, 72, 153], 'singleton' => false],
        ['x' => 72, 'y' => 18, 'letter' => 'u', 'hue' => [244, 114, 182], 'singleton' => false],
        ['x' => 83, 'y' => 18, 'letter' => 'u', 'hue' => [219, 39, 119], 'singleton' => false],
        ['x' => 83, 'y' => 11, 'letter' => 'u', 'hue' => [190, 24, 93], 'singleton' => false],
        // ---- p  (origin x=88)
        ['x' => 88, 'y' => 11, 'letter' => 'p2', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 88, 'y' => 18, 'letter' => 'p2', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 88, 'y' => 24, 'letter' => 'p2', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 96, 'y' => 11, 'letter' => 'p2', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 99, 'y' => 15, 'letter' => 'p2', 'hue' => [245, 158, 11], 'singleton' => false],
        ['x' => 96, 'y' => 18, 'letter' => 'p2', 'hue' => [245, 158, 11], 'singleton' => false],
        // ---- decoy singletons — dim #CBD5E1, never unioned, they just twinkle
        ['x' => 4, 'y' => 6, 'letter' => '', 'hue' => [203, 213, 225], 'singleton' => true],
        ['x' => 19, 'y' => 6, 'letter' => '', 'hue' => [203, 213, 225], 'singleton' => true],
        ['x' => 31, 'y' => 5, 'letter' => '', 'hue' => [203, 213, 225], 'singleton' => true],
        ['x' => 47, 'y' => 6, 'letter' => '', 'hue' => [203, 213, 225], 'singleton' => true],
        ['x' => 63, 'y' => 22, 'letter' => '', 'hue' => [203, 213, 225], 'singleton' => true],
        ['x' => 79, 'y' => 5, 'letter' => '', 'hue' => [203, 213, 225], 'singleton' => true],
        ['x' => 103, 'y' => 20, 'letter' => '', 'hue' => [203, 213, 225], 'singleton' => true],
        ['x' => 3, 'y' => 21, 'letter' => '', 'hue' => [203, 213, 225], 'singleton' => true],
    ];

    /**
     * Scored pairs above EDGE_THRESHOLD (surviving candidates — the detector's
     * edge list). The cycle-closing edges (5,1), (16,12), (22,18) and (32,28)
     * land inside an already-merged component and trigger the visible find()
     * compression pass.
     *
     * @var list<array{0: int, 1: int}>
     */
    public const EDGE_PAIRS = [
        [0, 1], [1, 2], [0, 3], [3, 4], [4, 5], [5, 1],              // p0
        [6, 7], [7, 8], [7, 9], [9, 10],                              // h
        [11, 12], [12, 13], [11, 14], [14, 15], [15, 16], [16, 12],  // p1
        [17, 18], [18, 19], [18, 20], [20, 21], [21, 22], [22, 18],  // d (bowl joins at x-height)
        [23, 24], [24, 25], [25, 26],                                // u
        [27, 28], [28, 29], [27, 30], [30, 31], [31, 32], [32, 28],  // p2
    ];

    /** A near-miss pair the gate rejects (score < threshold): never lights up. */
    public const NEAR_MISS_PAIR = [2, 5];

    /** Number of stars; keeps STARS offsets provably in range for dot lookups. */
    public const STAR_COUNT = 41;

    // ================================================================ public API

    /** Pure truecolor frame for the given tick (0..LAST_TICK); byte-reproducible. */
    public function frame(int $tick): string
    {
        return $this->frameAtDepth($tick, self::DEPTH_TRUECOLOR);
    }

    public function frameAtDepth(int $tick, string $depth): string
    {
        if ($tick < 0 || $tick > self::LAST_TICK) {
            throw new \InvalidArgumentException("tick $tick outside 0.." . self::LAST_TICK);
        }
        if ($depth === self::DEPTH_PLAIN) {
            return $this->plain();
        }
        if ($depth !== self::DEPTH_TRUECOLOR && $depth !== self::DEPTH_256) {
            throw new \InvalidArgumentException("unknown depth '$depth'");
        }

        return $this->encode($this->cells($this->dotGrid($tick)), $depth);
    }

    public function finalFrame(): string
    {
        return $this->frame(self::LAST_TICK);
    }

    /** Zero-escape static fallback: the finished constellation, glyphs only. */
    public function plain(): string
    {
        return $this->glyphText($this->dotGrid(self::LAST_TICK));
    }

    /**
     * Full one-shot stream ending on the hold frame: cat it in a truecolor
     * terminal (7+ free rows) to replay.
     *
     * @param self::DEPTH_* $depth
     */
    public function stream(string $depth = self::DEPTH_TRUECOLOR): string
    {
        if ($depth === self::DEPTH_PLAIN) {
            return $this->plain();
        }

        $frames = [];
        for ($tick = 0; $tick <= self::LAST_TICK; $tick++) {
            $frames[] = $this->frameAtDepth($tick, $depth);
        }

        return "\e[?25l" . implode("\e[" . self::ROWS . "A\r", $frames) . "\e[?25h";
    }

    /**
     * Play with real pacing, then hold the final frame.
     *
     * @param resource $out
     */
    public function play($out = STDOUT, bool $decorated = true, bool $noAnsi = false): void
    {
        $depth = self::resolveMode($decorated, $noAnsi);
        if ($depth === self::DEPTH_PLAIN) {
            \fwrite($out, $this->plain());

            return;
        }

        \fwrite($out, "\e[?25l");
        for ($tick = 0; $tick <= self::LAST_TICK; $tick++) {
            if ($tick > 0) {
                \fwrite($out, "\e[" . self::ROWS . "A\r");
                \usleep(self::TICK_USEC);
            }
            \fwrite($out, $this->frameAtDepth($tick, $depth));
        }
        \fwrite($out, "\e[?25h");
    }

    /**
     * Fallback chain: truecolor when COLORTERM advertises it, xterm-256
     * otherwise, plain text for undecorated / NO_COLOR / --no-ansi.
     *
     * @return self::DEPTH_*
     */
    public static function resolveMode(bool $decorated = true, bool $noAnsi = false, bool $noColor = false): string
    {
        if (!$decorated || $noAnsi || $noColor) {
            return self::DEPTH_PLAIN;
        }
        $colorterm = \getenv('COLORTERM');
        if (is_string($colorterm) && \preg_match('/truecolor|24bit/', $colorterm) === 1) {
            return self::DEPTH_TRUECOLOR;
        }

        return self::DEPTH_256;
    }

    // ================================================================ testability API

    /** Seeded similarity of edge $i — always strictly above EDGE_THRESHOLD. */
    public static function edgeScore(int $i): float
    {
        return round(self::EDGE_THRESHOLD + 0.001 + 0.18 * self::hash($i, 7, 0), 4);
    }

    /** Below-threshold score of the near-miss pair (proof the gate is real). */
    public static function nearMissScore(): float
    {
        return round(self::EDGE_THRESHOLD - 0.01 - 0.05 * self::hash(99, 11, 0), 4);
    }

    /**
     * Edge indices in union order: score desc, index asc as tie-break.
     *
     * @return list<int>
     */
    public static function activationOrder(): array
    {
        $order = array_keys(self::EDGE_PAIRS);
        usort($order, static fn (int $a, int $b): int => self::edgeScore($b) <=> self::edgeScore($a) ?: $a <=> $b);

        return $order;
    }

    /** Tick at which the given edge fires (UNION_START..UNION_END). */
    public static function activationTick(int $edgeIndex): int
    {
        $rank = (int) array_search($edgeIndex, self::activationOrder(), true);

        return self::UNION_START + intdiv($rank * (self::UNION_END - self::UNION_START + 1), count(self::EDGE_PAIRS));
    }

    /**
     * Hue the star displays at $tick = hue of its computed union-find root.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function rootHueAt(int $tick, int $starId): array
    {
        $parent = $this->simulate($tick)['parent'];

        return self::STARS[$this->findRoot($starId, $parent)]['hue'];
    }

    /**
     * All union/compression events of the finished run.
     * members = flashed component, root = component's root star,
     * sparkFrom = star whose line visibly re-points to root (-1 = already flat).
     *
     * @return list<array{tick: int, members: list<int>, root: int, sparkFrom: int}>
     */
    public function flashEvents(): array
    {
        return $this->simulate(self::LAST_TICK)['events'];
    }

    /**
     * Path-compression sparks: lines straight from a star to the root it now
     * answers to.
     *
     * @return list<array{tick: int, from: int, to: int}>
     */
    public function sparkEvents(): array
    {
        $sparks = [];
        foreach ($this->flashEvents() as $e) {
            if ($e['sparkFrom'] >= 0) {
                $sparks[] = ['tick' => $e['tick'], 'from' => $e['sparkFrom'], 'to' => $e['root']];
            }
        }

        return $sparks;
    }

    // ================================================================ the real union-find

    /**
     * Replay the seeded edge stream up to $tick through a genuine union-find
     * (path-halving find + union-by-rank, same shape as Clusterer::UnionFind),
     * recording each union's flashed component and compression spark.
     *
     * @return array{parent: array<int,int>, rank: array<int,int>, events: list<array{tick: int, members: list<int>, root: int, sparkFrom: int}>}
     */
    private function simulate(int $tick): array
    {
        $parent = [];
        $rank = [];
        foreach (self::STARS as $id => $_) {
            $parent[$id] = $id;
            $rank[$id] = 0;
        }
        /** @var list<array{tick: int, members: list<int>, root: int, sparkFrom: int}> $events */
        $events = [];

        foreach (self::activationOrder() as $edgeIndex) {
            $fire = self::activationTick($edgeIndex);
            if ($fire > $tick) {
                break;
            }
            [$a, $b] = self::EDGE_PAIRS[$edgeIndex];
            $ra = $this->findRoot($a, $parent);
            $rb = $this->findRoot($b, $parent);

            if ($ra !== $rb) {
                $compA = $this->component($ra, $parent);
                $compB = $this->component($rb, $parent);
                if ($rank[$ra] < $rank[$rb]) {
                    $parent[$ra] = $rb;
                    $root = $rb;
                    $moved = $compA;
                } elseif ($rank[$rb] < $rank[$ra]) {
                    $parent[$rb] = $ra;
                    $root = $ra;
                    $moved = $compB;
                } else {
                    $parent[$rb] = $ra;
                    $rank[$ra]++;
                    $root = $ra;
                    $moved = $compB;
                }
                $events[] = [
                    'tick' => $fire,
                    'members' => $moved,
                    'root' => $root,
                    'sparkFrom' => $this->pickSpark($moved, $root, $parent),
                ];
                continue;
            }

            // cycle-closing edge inside one component: the detector's find()
            // pass compresses every member's path — the re-pointed members flash.
            $before = $parent;
            $moved = [];
            foreach ($this->component($ra, $parent) as $id) {
                $this->findRoot($id, $parent);
                if ($parent[$id] !== $before[$id]) {
                    $moved[] = $id;
                }
            }
            if ($moved === []) {
                continue;
            }
            $events[] = [
                'tick' => $fire,
                'members' => $moved,
                'root' => $ra,
                'sparkFrom' => $this->pickSpark($moved, $ra, $parent),
            ];
        }

        return ['parent' => $parent, 'rank' => $rank, 'events' => $events];
    }

    /**
     * Clusterer::UnionFind::find — path halving — in spirit, byte for byte.
     *
     * @param array<int,int> $parent
     */
    private function findRoot(int $x, array &$parent): int
    {
        while ($parent[$x] !== $x) {
            $parent[$x] = $parent[$parent[$x]];
            $x = $parent[$x];
        }

        return $x;
    }

    /**
     * @param array<int,int> $parent
     * @return list<int>
     */
    private function component(int $root, array $parent): array
    {
        $ids = [];
        foreach (array_keys($parent) as $id) {
            if ($this->findRoot($id, $parent) === $root) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The star whose line visibly re-points straight to the root: the lowest
     * member id still hanging off an intermediate parent. -1 = already flat.
     *
     * @param list<int> $members
     * @param array<int,int> $parent
     */
    private function pickSpark(array $members, int $root, array $parent): int
    {
        foreach ($members as $id) {
            if ($id !== $root && $parent[$id] !== $root) {
                return $id;
            }
        }

        return -1;
    }

    // ================================================================ frame composition

    /**
     * Paint the tick onto the dot grid. Dots carry a colour + a paint priority
     * (1 edge, 2 fresh edge, 3 compression spark, 4 star) so overlaps resolve
     * deterministically.
     *
     * @return array{color: array<int,array<int,array{0: int, 1: int, 2: int}>>, prio: array<int,array<int,int>>}
     */
    private function dotGrid(int $tick): array
    {
        $prio = array_fill(0, self::DOT_H, array_fill(0, self::DOT_W, 0));
        $color = array_fill(0, self::DOT_H, array_fill(0, self::DOT_W, self::WHITE));

        $sim = $this->simulate($tick);
        $this->paintEdges($tick, $sim['parent'], $prio, $color);
        $this->paintSparks($tick, $sim['events'], $prio, $color);
        $this->paintStars($tick, $sim['parent'], $sim['events'], $prio, $color);

        return ['color' => $color, 'prio' => $prio];
    }

    /**
     * Constellation lines: every edge whose score fired by this tick, drawn as
     * a braille dot line, tinted with the displayed root hue of its component.
     *
     * @param array<int,int> $parent
     * @param array<int,array<int,int>> $prio
     * @param array<int,array<int,array{0: int, 1: int, 2: int}>> $color
     */
    private function paintEdges(int $tick, array $parent, array &$prio, array &$color): void
    {
        foreach (self::EDGE_PAIRS as $i => [$a, $b]) {
            $fire = self::activationTick($i);
            if ($fire > $tick) {
                continue;
            }
            $root = $this->findRoot($a, $parent);
            $rgb = self::scale(self::STARS[$root]['hue'], $this->lineBrightness($tick));
            $age = max(0, $tick - $fire);
            if ($age < self::FLASH_SPAN) {
                $rgb = self::mix($rgb, self::WHITE, [0.75, 0.40, 0.15][$age]);
            }
            $this->drawDotLine($prio, $color, self::STARS[$a], self::STARS[$b], $rgb, $age < self::FLASH_SPAN ? 2 : 1);
        }
    }

    /**
     * Path-compression sparks: a straight bright line from the re-pointed star
     * to the root star it now answers to, visible for FLASH_SPAN ticks.
     *
     * @param list<array{tick: int, members: list<int>, root: int, sparkFrom: int}> $events
     * @param array<int,array<int,int>> $prio
     * @param array<int,array<int,array{0: int, 1: int, 2: int}>> $color
     */
    private function paintSparks(int $tick, array $events, array &$prio, array &$color): void
    {
        foreach ($events as $e) {
            $delta = $tick - $e['tick'];
            $from = $e['sparkFrom'];
            $to = $e['root'];
            if ($from < 0 || $from >= self::STAR_COUNT || $to < 0 || $to >= self::STAR_COUNT) {
                continue;
            }

            if ($delta < 0 || $delta >= self::FLASH_SPAN) {
                continue;
            }

            $rgb = self::mix(self::ACCENT, self::WHITE, [0.85, 0.50, 0.20][$delta]);
            $this->drawDotLine($prio, $color, self::STARS[$from], self::STARS[$to], $rgb, 3);
        }
    }

    /**
     * Stars: 5-dot plus sprites for wordmark vertices (root hue, seeded
     * fade-in, union flash, twinkle), 2-dot pips for dim decoy singletons.
     *
     * @param array<int,int> $parent
     * @param list<array{tick: int, members: list<int>, root: int, sparkFrom: int}> $events
     * @param array<int,array<int,int>> $prio
     * @param array<int,array<int,array{0: int, 1: int, 2: int}>> $color
     */
    private function paintStars(int $tick, array $parent, array $events, array &$prio, array &$color): void
    {
        /** @var array<int, float> $flashLevel star id -> white-hot mix level right now */
        $flashLevel = [];
        foreach ($events as $e) {
            $delta = $tick - $e['tick'];
            if ($delta < 0 || $delta >= self::FLASH_SPAN) {
                continue;
            }
            foreach ($e['members'] as $id) {
                $flashLevel[$id] = max([0.85, 0.40, 0.10][$delta], $flashLevel[$id] ?? 0.0);
            }
        }

        foreach (self::STARS as $id => $star) {
            $fade = $this->fadeIn($tick, $id);
            if ($fade <= 0.0) {
                continue;
            }
            $root = $this->findRoot($id, $parent);
            $rgb = self::scale(self::STARS[$root]['hue'], $star['singleton'] ? 0.5 : 1.0);
            $rgb = self::scale($rgb, $fade * $this->twinkle($tick, $id, $star['singleton']));
            if (!$star['singleton'] && isset($flashLevel[$id])) {
                $rgb = self::mix($rgb, self::WHITE, $flashLevel[$id]);
            }
            $this->putDot($prio, $color, $star['x'], $star['y'], $rgb, 4);
            if ($star['singleton']) {
                $this->putDot($prio, $color, $star['x'] + 1, $star['y'] - 1, $rgb, 4);
                continue;
            }
            $this->putDot($prio, $color, $star['x'] - 1, $star['y'], $rgb, 4);
            $this->putDot($prio, $color, $star['x'] + 1, $star['y'], $rgb, 4);
            $this->putDot($prio, $color, $star['x'], $star['y'] - 1, $rgb, 4);
            $this->putDot($prio, $color, $star['x'], $star['y'] + 1, $rgb, 4);
        }
    }

    /** Seeded stagger across the fade-in window: ticks 0..11, 3-tick ramp. */
    private function fadeIn(int $tick, int $starId): float
    {
        $start = (int) floor(self::hash($starId, 3, 0) * self::UNION_START);

        return max(0.0, min(1.0, ($tick - $start + 1) / 3));
    }

    /** Living sky flicker — amplitude drops to zero on the held final tick. */
    private function twinkle(int $tick, int $starId, bool $singleton): float
    {
        if ($tick >= self::LAST_TICK) {
            return 1.0;
        }
        $spread = $singleton ? 0.55 : 0.30;

        return 1.0 - $spread / 2 + $spread * self::hash($starId, $tick, 9);
    }

    /** Line dim: 0.55 through the union phase, ramping to full just before the hold. */
    private function lineBrightness(int $tick): float
    {
        if ($tick < self::BRIGHT_START) {
            return 0.55;
        }

        return 0.55 + 0.45 * min(1.0, ($tick - self::BRIGHT_START) / (self::LAST_TICK - 1 - self::BRIGHT_START));
    }

    /**
     * Bresenham dot line between two stars, trimmed at their sprites.
     *
     * @param array<int,array<int,int>> $prio
     * @param array<int,array<int,array{0: int, 1: int, 2: int}>> $color
     * @param array{x: int, y: int, letter: string, hue: array{0: int, 1: int, 2: int}, singleton: bool} $a
     * @param array{x: int, y: int, letter: string, hue: array{0: int, 1: int, 2: int}, singleton: bool} $b
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private function drawDotLine(array &$prio, array &$color, array $a, array $b, array $rgb, int $priority): void
    {
        $x = (int) $a['x'];
        $y = (int) $a['y'];
        $x1 = (int) $b['x'];
        $y1 = (int) $b['y'];
        $dx = abs($x1 - $x);
        $dy = -abs($y1 - $y);
        $sx = $x < $x1 ? 1 : -1;
        $sy = $y < $y1 ? 1 : -1;
        $err = $dx + $dy;
        for ($guard = 0; $guard < self::DOT_W + self::DOT_H; $guard++) {
            if (!$this->nearStar($x, $y, $a) && !$this->nearStar($x, $y, $b)) {
                $this->putDot($prio, $color, $x, $y, $rgb, $priority);
            }
            if ($x === $x1 && $y === $y1) {
                return;
            }
            $e2 = 2 * $err;
            if ($e2 >= $dy) {
                $err += $dy;
                $x += $sx;
            }
            if ($e2 <= $dx) {
                $err += $dx;
                $y += $sy;
            }
        }
    }

    /** @param array{x: int, y: int, letter: string, hue: array{0: int, 1: int, 2: int}, singleton: bool} $star */
    private function nearStar(int $x, int $y, array $star): bool
    {
        return abs($x - (int) $star['x']) <= 1 && abs($y - (int) $star['y']) <= 1;
    }

    /**
     * @param array<int,array<int,int>> $prio
     * @param array<int,array<int,array{0: int, 1: int, 2: int}>> $color
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private function putDot(array &$prio, array &$color, int $x, int $y, array $rgb, int $priority): void
    {
        if ($x < 0 || $x >= self::DOT_W || $y < 0 || $y >= self::DOT_H) {
            return;
        }
        if ($priority >= $prio[$y][$x]) {
            $prio[$y][$x] = $priority;
            $color[$y][$x] = $rgb;
        }
    }

    /**
     * Fold the dot grid into 7x56 coloured cells: braille glyph + fg + bg.
     *
     * @param DotGrid $grid
     * @return list<list<array{0: string, 1: ?Rgb, 2: Rgb}>>
     */
    private function cells(array $grid): array
    {
        $out = [];
        foreach ($this->cellDots($grid) as $cy => [$row, $bg]) {
            $cells = [];
            foreach ($row as [$glyph, $fg]) {
                $cells[] = [$glyph, $fg, $bg];
            }
            $out[] = $cells;
        }

        return $out;
    }

    /**
     * Fold the dot grid into the zero-escape plain text frame.
     *
     * @param DotGrid $grid
     */
    private function glyphText(array $grid): string
    {
        $out = '';
        foreach ($this->cellDots($grid) as [$row, $_bg]) {
            foreach ($row as [$glyph, $_fg]) {
                $out .= $glyph;
            }
            $out .= "\n";
        }

        return $out;
    }

    /**
     * Shared fold: every cell's braille glyph, its dot-priority colour, and the
     * sky gradient bg for its row.
     *
     * @param array{color: array<int,array<int,array{0: int, 1: int, 2: int}>>, prio: array<int,array<int,int>>} $grid
     * @return list<array{0: list<array{0: string, 1: ?array{0: int, 1: int, 2: int}}>, 1: array{0: int, 1: int, 2: int}}>
     */
    private function cellDots(array $grid): array
    {
        $rows = [];
        for ($cy = 0; $cy < self::ROWS; $cy++) {
            $bg = self::mix(self::SKY_TOP, self::SKY_BOTTOM, $cy / (self::ROWS - 1));
            $row = [];
            for ($cx = 0; $cx < self::COLS; $cx++) {
                $bits = 0;
                $fg = null;
                $best = 0;
                for ($col = 0; $col < 2; $col++) {
                    for ($drow = 0; $drow < 4; $drow++) {
                        $dx = $cx * 2 + $col;
                        $dy = $cy * 4 + $drow;
                        if ($grid['prio'][$dy][$dx] === 0) {
                            continue;
                        }
                        $bits |= self::dotBit($col, $drow);
                        if ($grid['prio'][$dy][$dx] > $best) {
                            $best = $grid['prio'][$dy][$dx];
                            $fg = $grid['color'][$dy][$dx];
                        }
                    }
                }
                $glyph = $bits === 0 ? ' ' : (string) mb_chr(0x2800 + $bits, 'UTF-8');
                $row[] = [$glyph, $fg];
            }
            $rows[] = [$row, $bg];
        }

        return $rows;
    }

    /** Standard braille dot bits: even column dots 1/3/5/7, odd column dots 2/4/6/8. */
    private static function dotBit(int $col, int $row): int
    {
        return $col === 0 ? [0x01, 0x04, 0x10, 0x40][$row] : [0x02, 0x08, 0x20, 0x80][$row];
    }

    // ================================================================ encoding (shared plumbing)

    /**
     * @param list<list<array{0: string, 1: ?array{0: int, 1: int, 2: int}, 2: ?array{0: int, 1: int, 2: int}}>> $cells
     * @param self::DEPTH_TRUECOLOR|self::DEPTH_256 $depth
     */
    private function encode(array $cells, string $depth): string
    {
        $out = '';
        foreach ($cells as $row) {
            $curFg = '39';
            $curBg = '49';
            $line = '';
            foreach ($row as [$ch, $fg, $bg]) {
                $codes = [];
                $bgSgr = $this->sgr($bg, $depth, true);
                if ($bgSgr !== $curBg) {
                    $codes[] = $curBg = $bgSgr;
                }
                if ($ch !== ' ') {
                    $fgSgr = $this->sgr($fg, $depth, false);
                    if ($fgSgr !== $curFg) {
                        $codes[] = $curFg = $fgSgr;
                    }
                }
                if ($codes !== []) {
                    $line .= "\e[" . implode(';', $codes) . 'm';
                }
                $line .= $ch;
            }
            $out .= $line . "\e[0m\n";
        }

        return $out;
    }

    /** @param ?array{0: int, 1: int, 2: int} $c */
    private function sgr(?array $c, string $depth, bool $bg): string
    {
        if ($c === null) {
            return $bg ? '49' : '39';
        }

        return $depth === self::DEPTH_256
            ? ($bg ? '48;5;' : '38;5;') . self::to256($c)
            : sprintf('%s;2;%d;%d;%d', $bg ? '48' : '38', $c[0], $c[1], $c[2]);
    }

    /**
     * Nearest xterm-256 palette entry (6x6x6 cube + grayscale ramp).
     *
     * @param array{0: int, 1: int, 2: int} $c
     */
    public static function to256(array $c): int
    {
        static $memo = [];
        $key = "$c[0],$c[1],$c[2]";
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $best = 16;
        $bestDist = INF;
        $steps = [0, 95, 135, 175, 215, 255];
        $idx = 16;
        for ($r = 0; $r < 6; $r++) {
            for ($g = 0; $g < 6; $g++) {
                for ($b = 0; $b < 6; $b++) {
                    $d = self::redmean($c, [$steps[$r], $steps[$g], $steps[$b]]);
                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $best = $idx;
                    }
                    $idx++;
                }
            }
        }
        for ($i = 0; $i < 24; $i++) {
            $v = 8 + $i * 10;
            $d = self::redmean($c, [$v, $v, $v]);
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = 232 + $i;
            }
        }

        return $memo[$key] = $best;
    }

    /**
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     */
    private static function redmean(array $a, array $b): float
    {
        $rm = ($a[0] + $b[0]) / 2;
        $dr = $a[0] - $b[0];
        $dg = $a[1] - $b[1];
        $db = $a[2] - $b[2];

        return (2 + $rm / 256) * $dr * $dr + 4 * $dg * $dg + (2 + (255 - $rm) / 256) * $db * $db;
    }

    // ================================================================ colour math

    /**
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     * @return array{0: int, 1: int, 2: int}
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
     * @param array{0: int, 1: int, 2: int} $c
     * @return array{0: int, 1: int, 2: int}
     */
    private static function scale(array $c, float $k): array
    {
        return [
            (int) max(0, min(255, round($c[0] * $k))),
            (int) max(0, min(255, round($c[1] * $k))),
            (int) max(0, min(255, round($c[2] * $k))),
        ];
    }

    /** Deterministic hash noise in [0,1) for (x, y, tick) — seeded by SEED. */
    private static function hash(int $x, int $y, int $tick): float
    {
        $h = ($x * 374761393 + $y * 668265263 + $tick * 2147483647 + self::SEED) & 0xFFFFFFFF;
        $h = (($h ^ ($h >> 13)) * 1274126177) & 0xFFFFFFFF;

        return (($h ^ ($h >> 16)) & 0xFFFFFF) / 0x1000000;
    }
}
