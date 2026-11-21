<?php

declare(strict_types=1);

/**
 * TreeGrowthBanner — phpdup animated ANSI logo, concept 3 "Syntax Tree Growth".
 *
 * Two AST trees grow upward from a shared ground line, one node per tick,
 * drawn with box-drawing branches. The two structurally IDENTICAL inner
 * subtrees (same seed, same 10-cell shape — translated by 22 columns,
 * never mirrored) grow in emerald and are joined by dotted bridges; the outer
 * unmatched branches stay slate; trunks and the wordmark ground line are
 * bark. The canopies merge last under a solid arch and the banner holds on
 * the final, fully settled frame.
 *
 * Ties to phpdup: the trees are ASTs; emerald = the same subtree hashed /
 * aligned by APTED on both sides; bridges = the detected clone link.
 *
 * Hard owner budget (round 2, small-banner scale):
 *   - one-shot playback <= 1.5 s at <= 30 fps (<= 45 ticks), then HOLD;
 *   - final logo <= 7 rows x <= 56 cols, no overflow at 80 cols;
 *   - truecolor SGR, 256-color fallback, zero-escape plain fallback;
 *   - frame() is pure: deterministic in ($tick, $seed), no wall clock.
 *
 * Phase map (tick counts are seed-independent):
 *   tick 0        ground line only
 *   ticks 1..27   growth — 14 reveals (both trees advance per tick, same
 *                 seed): each node appears one tick in a bright growth
 *                 pulse, settles the next
 *   ticks 29..34  dotted bridges march outward from the centre
 *   ticks 35..37  canopy arch grows from both edges and meets in the middle
 *   tick  38      fully settled HOLD frame (39 frames, 1.27 s playback)
 *
 * Seed variation (all deterministic, identity-preserving): node-dot glyph
 * (●/◆/◉), bridge glyph (·/┄) and leaf spacing (±3/±4 columns).
 */
final class TreeGrowthBanner
{
    public const MODE_TRUECOLOR = 'truecolor';
    public const MODE_256 = 'color256';
    public const MODE_PLAIN = 'plain';

    public const ROWS = 7;
    public const COLS = 56;
    public const FPS = 30;
    public const FRAME_INTERVAL_US = 33333; // 1/30 s — at the 30 fps cap
    public const MAX_TICKS = 45;            // owner hard cap (verify asserts)

    /** Owner palette (24-bit). */
    public const BARK_RGB = [146, 64, 14];      // #92400E  trunks, ground, wordmark
    public const EMERALD_RGB = [16, 185, 129];  // #10B981  matched subtrees, bridges, canopy arch
    public const SLATE_RGB = [100, 116, 139];   // #64748B  unmatched branches

    private const PULSE_TOWARD_WHITE = 0.35;    // growth-tip brighten on appear tick

    private const GLYPH_BRANCH_LEFT = '└';
    private const GLYPH_BRANCH_RIGHT = '┘';
    private const GLYPH_JUNCTION = '┬';
    private const GLYPH_DASH = '─';
    private const GLYPH_ARCH_LEFT = '┌';
    private const GLYPH_ARCH_RIGHT = '┐';

    private const WORDMARK = 'phpdup';

    /** Rows counted top-down: 0 = canopy arch row, 6 = ground. */
    private const ROW_CANOPY = 0;
    private const ROW_LEAVES = 1;
    private const ROW_LEAF_BARS = 2;
    private const ROW_BRANCHES = 3;
    private const ROW_BRANCH_BARS = 4;
    private const ROW_ROOTS = 5;
    private const ROW_GROUND = 6;

    private const LEFT_ROOT_COL = 12;
    private const RIGHT_ROOT_COL = 44;
    private const LEFT_OUTER_COL = 7;    // unmatched (slate) branch
    private const LEFT_INNER_COL = 17;   // matched (emerald) subtree root
    private const RIGHT_INNER_COL = 39;  // matched (emerald) subtree root
    private const RIGHT_OUTER_COL = 49;  // unmatched (slate) branch
    private const CENTRE_COL = 28;

    /** Bridge phase gets at most this many ticks (phase-proportional budget). */
    private const BRIDGE_TICK_BUDGET = 6;

    private int $seed;
    private int $leafSpacing;
    private string $nodeDot;
    private string $bridgeDot;

    /** @var list<array{tick: int, cells: list<array{row: int, col: int, glyph: string, color: string}>}> */
    private array $reveals = [];

    /** @var list<array{row: int, col: int, glyph: string, color: string}> */
    private array $groundCells = [];

    /** @var array{left: list<array{row: int, col: int, glyph: string}>, right: list<array{row: int, col: int, glyph: string}>, bridge: list<array{row: int, col: int, glyph: string}>, arch: list<array{row: int, col: int, glyph: string}>, offset: int} */
    private array $emeraldAnatomy;

    private int $finalTick = 0;

    public function __construct(int $seed = 0)
    {
        $this->seed = $seed;
        // Knuth multiplicative hash, kept inside 2^32 so PHP int never overflows
        $hash = (($seed & 0x7FFFFFFF) * 2654435761) % 4294967296;

        /** @var list<string> $nodeDots */
        $nodeDots = ['●', '◆', '◉'];
        /** @var list<string> $bridgeDots */
        $bridgeDots = ['·', '┄'];
        $this->nodeDot = $nodeDots[(int) ($hash % count($nodeDots))];
        $this->bridgeDot = $bridgeDots[(int) (intdiv($hash, 3) % count($bridgeDots))];
        $this->leafSpacing = intdiv($hash, 6) % 2 === 0 ? 3 : 4;

        $this->buildGround();
        $this->buildReveals();
        $this->buildEmeraldAnatomy();
    }

    // ============================================================ public API

    public function frameCount(): int
    {
        return $this->finalTick + 1;
    }

    public function finalTick(): int
    {
        return $this->finalTick;
    }

    /**
     * Seed the banner was built from (useful for repros and for a wiring
     * lane to persist the chosen variant).
     *
     * @psalm-api
     */
    public function seed(): int
    {
        return $this->seed;
    }

    public function leafSpacing(): int
    {
        return $this->leafSpacing;
    }

    /** Pure truecolor frame at $tick (0 .. finalTick()). */
    public function frame(int $tick): string
    {
        return $this->render($tick, self::MODE_TRUECOLOR);
    }

    public function render(int $tick, string $mode = self::MODE_TRUECOLOR): string
    {
        if ($tick < 0 || $tick > $this->finalTick) {
            throw new InvalidArgumentException("tick $tick out of range 0..{$this->finalTick}");
        }
        if (!in_array($mode, [self::MODE_TRUECOLOR, self::MODE_256, self::MODE_PLAIN], true)) {
            throw new InvalidArgumentException("unknown mode '$mode'");
        }

        return $this->emit($this->visibleCells($tick), $mode);
    }

    /** Final held frame as plain text — zero escape codes. */
    public function staticPlain(): string
    {
        return $this->render($this->finalTick, self::MODE_PLAIN);
    }

    /**
     * Full one-shot stream: every frame joined by a cursor-up redraw, ending
     * on the settled hold frame. Plain mode is the static frame alone.
     */
    public function stream(string $mode = self::MODE_TRUECOLOR): string
    {
        if ($mode === self::MODE_PLAIN) {
            return $this->render($this->finalTick, self::MODE_PLAIN);
        }

        $frames = [];
        for ($tick = 0; $tick <= $this->finalTick; $tick++) {
            $frames[] = $this->render($tick, $mode);
        }

        return "\e[?25l" . implode("\e[" . self::ROWS . 'A' . "\r", $frames) . "\e[?25h";
    }

    /**
     * Writes the one-shot animation to STDOUT, holding the final frame.
     *
     * @psalm-api
     */
    public function play(?string $mode = null): void
    {
        $mode ??= self::detectMode();
        if ($mode === self::MODE_PLAIN) {
            fwrite(STDOUT, $this->staticPlain());

            return;
        }

        for ($tick = 0; $tick <= $this->finalTick; $tick++) {
            fwrite(STDOUT, $this->render($tick, $mode));
            if ($tick < $this->finalTick) {
                usleep(self::FRAME_INTERVAL_US);
            }
        }
    }

    /** Capability probe honouring NO_COLOR / COLORTERM / TERM, per owner spec. */
    public static function detectMode(): string
    {
        if (getenv('NO_COLOR') !== false) {
            return self::MODE_PLAIN;
        }
        $colorTerm = strtolower((string) getenv('COLORTERM'));
        if (str_contains($colorTerm, 'truecolor') || str_contains($colorTerm, '24bit')) {
            return self::MODE_TRUECOLOR;
        }
        if (str_contains(strtolower((string) getenv('TERM')), '256color')) {
            return self::MODE_256;
        }

        return self::MODE_PLAIN;
    }

    /**
     * The emerald cells that tell the "identity" story. Both matched
     * subtrees are the SAME translated pattern (offset = right-inner node
     * col minus left-inner node col) — never mirrored. bridge/arch are
     * listed separately so a verifier can subtract them from the rendered
     * emerald set and compare the two patterns head to head.
     *
     * @return array{left: list<array{row: int, col: int, glyph: string}>, right: list<array{row: int, col: int, glyph: string}>, bridge: list<array{row: int, col: int, glyph: string}>, arch: list<array{row: int, col: int, glyph: string}>, offset: int}
     */
    public function emeraldAnatomyAPI(): array
    {
        return $this->emeraldAnatomy;
    }

    /**
     * The two emerald graft arms attaching the matched subtrees to their
     * trunks (dash run + corner, rows 4). Attachment-side glyphs mirror,
     * so they are emerald but deliberately NOT part of the identity claim.
     *
     * @return list<array{row: int, col: int, glyph: string}>
     */
    public function graftCells(): array
    {
        $cells = [];
        for ($col = self::LEFT_ROOT_COL + 1; $col < self::LEFT_INNER_COL; $col++) {
            $cells[] = ['row' => self::ROW_BRANCH_BARS, 'col' => $col, 'glyph' => self::GLYPH_DASH];
        }
        $cells[] = ['row' => self::ROW_BRANCH_BARS, 'col' => self::LEFT_INNER_COL, 'glyph' => self::GLYPH_BRANCH_RIGHT];
        for ($col = self::RIGHT_INNER_COL + 1; $col < self::RIGHT_ROOT_COL; $col++) {
            $cells[] = ['row' => self::ROW_BRANCH_BARS, 'col' => $col, 'glyph' => self::GLYPH_DASH];
        }
        $cells[] = ['row' => self::ROW_BRANCH_BARS, 'col' => self::RIGHT_INNER_COL, 'glyph' => self::GLYPH_BRANCH_LEFT];

        return $cells;
    }

    // ============================================================ schedule

    private function buildGround(): void
    {
        $wordmarkStart = intdiv(self::COLS - strlen(self::WORDMARK), 2); // col 25
        $row = str_repeat(self::GLYPH_DASH, $wordmarkStart - 1)
            . ' ' . self::WORDMARK . ' '
            . str_repeat(self::GLYPH_DASH, self::COLS - $wordmarkStart - strlen(self::WORDMARK) - 1);

        // every ground glyph is bark: trunks plant into it, wordmark sits on it
        foreach (mb_str_split($row) as $col => $glyph) {
            if ($glyph === ' ') {
                continue; // single-space breathing room around the wordmark
            }
            $this->groundCells[] = $this->cell(self::ROW_GROUND, $col, $glyph, 'bark');
        }
    }

    private function buildReveals(): void
    {
        $tick = 1;

        // roots appear on the ground line
        $this->addReveal($tick, [
            $this->cell(self::ROW_ROOTS, self::LEFT_ROOT_COL, $this->nodeDot, 'bark'),
            $this->cell(self::ROW_ROOTS, self::RIGHT_ROOT_COL, $this->nodeDot, 'bark'),
        ]);
        $tick += 2;

        // trunk junctions — each root forks
        $this->addReveal($tick, [
            $this->cell(self::ROW_BRANCH_BARS, self::LEFT_ROOT_COL, self::GLYPH_JUNCTION, 'bark'),
            $this->cell(self::ROW_BRANCH_BARS, self::RIGHT_ROOT_COL, self::GLYPH_JUNCTION, 'bark'),
        ]);
        $tick += 2;

        // two branch pairs per tree: bars out, then node dot + sub-junction.
        // Inner pair is the matched (emerald) subtree; outer stays slate.
        foreach ([
            [self::LEFT_OUTER_COL, 'slate', self::RIGHT_INNER_COL, 'emerald'],
            [self::LEFT_INNER_COL, 'emerald', self::RIGHT_OUTER_COL, 'slate'],
        ] as [$leftBranchCol, $leftColour, $rightBranchCol, $rightColour]) {
            $this->addReveal($tick, array_merge(
                $this->branchBar(self::LEFT_ROOT_COL, $leftBranchCol, $leftColour),
                $this->branchBar(self::RIGHT_ROOT_COL, $rightBranchCol, $rightColour),
            ));
            $tick += 2;
            $this->addReveal($tick, array_merge(
                $this->nodePair($leftBranchCol, $leftColour),
                $this->nodePair($rightBranchCol, $rightColour),
            ));
            $tick += 2;
        }

        // leaves — outer edges of each subtree pair first, inward last
        foreach ([
            [self::LEFT_OUTER_COL, 'slate', self::RIGHT_OUTER_COL, 'slate'],
            [self::LEFT_INNER_COL, 'emerald', self::RIGHT_INNER_COL, 'emerald'],
        ] as [$leftSubCol, $leftColour, $rightSubCol, $rightColour]) {
            foreach ([-1 => self::GLYPH_BRANCH_LEFT, 1 => self::GLYPH_BRANCH_RIGHT] as $side => $corner) {
                $this->addReveal($tick, array_merge(
                    $this->leafBar($leftSubCol, $side, $corner, $leftColour),
                    $this->leafBar($rightSubCol, $side, $corner, $rightColour),
                ));
                $tick += 2;
                $this->addReveal($tick, [
                    $this->cell(self::ROW_LEAVES, $leftSubCol + $side * $this->leafSpacing, $this->nodeDot, $leftColour),
                    $this->cell(self::ROW_LEAVES, $rightSubCol + $side * $this->leafSpacing, $this->nodeDot, $rightColour),
                ]);
                $tick += 2;
            }
        }

        // bridge phase, then the canopy merge
        $this->addBridgeReveals($tick);
        $tick += $this->bridgeTickCount();
        $this->addArchReveals($tick);
        $this->finalTick = $tick + $this->archTickCount(); // one fully settled HOLD frame
    }

    /** @param list<array{row: int, col: int, glyph: string, color: string}> $cells */
    private function addReveal(int $tick, array $cells): void
    {
        $this->reveals[] = ['tick' => $tick, 'cells' => $cells];
    }

    /**
     * Dash span from the trunk junction toward $branchCol, plus the corner
     * that points up into the branch node.
     *
     * @return list<array{row: int, col: int, glyph: string, color: string}>
     */
    private function branchBar(int $rootCol, int $branchCol, string $color): array
    {
        $outward = $branchCol < $rootCol;
        $step = $outward ? -1 : 1;
        $corner = $outward ? self::GLYPH_BRANCH_LEFT : self::GLYPH_BRANCH_RIGHT;

        $cells = [];
        for ($col = $rootCol + $step; $col !== $branchCol; $col += $step) {
            $cells[] = $this->cell(self::ROW_BRANCH_BARS, $col, self::GLYPH_DASH, $color);
        }
        $cells[] = $this->cell(self::ROW_BRANCH_BARS, $branchCol, $corner, $color);

        return $cells;
    }

    /**
     * Node dot plus the junction it grows up into its leaf bar.
     *
     * @return list<array{row: int, col: int, glyph: string, color: string}>
     */
    private function nodePair(int $col, string $color): array
    {
        return [
            $this->cell(self::ROW_BRANCHES, $col, $this->nodeDot, $color),
            $this->cell(self::ROW_LEAF_BARS, $col, self::GLYPH_JUNCTION, $color),
        ];
    }

    /**
     * Leaf bar: dashes out from the sub-junction plus the corner pointing
     * up into the leaf dot.
     *
     * @return list<array{row: int, col: int, glyph: string, color: string}>
     */
    private function leafBar(int $col, int $side, string $corner, string $color): array
    {
        $cells = [];
        for ($d = 1; $d < $this->leafSpacing; $d++) {
            $cells[] = $this->cell(self::ROW_LEAF_BARS, $col + $side * $d, self::GLYPH_DASH, $color);
        }
        $cells[] = $this->cell(self::ROW_LEAF_BARS, $col + $side * $this->leafSpacing, $corner, $color);

        return $cells;
    }

    /**
     * Dotted bridge across the centre gap at the leaves row, joining the
     * two matched subtrees' facing outer leaves.
     *
     * @return array{row: int, from: int, to: int}
     */
    private function leafBridge(): array
    {
        return [
            'row' => self::ROW_LEAVES,
            'from' => self::LEFT_INNER_COL + $this->leafSpacing + 1,
            'to' => self::RIGHT_INNER_COL - $this->leafSpacing - 1,
        ];
    }

    /**
     * Dotted bridge across the centre gap at the branches row, joining the
     * two matched subtree roots.
     *
     * @return array{row: int, from: int, to: int}
     */
    private function nodeBridge(): array
    {
        return [
            'row' => self::ROW_BRANCHES,
            'from' => self::LEFT_INNER_COL + 1,
            'to' => self::RIGHT_INNER_COL - 1,
        ];
    }

    /** @param array{row: int, from: int, to: int} $span */
    private function bridgeLevels(array $span): int
    {
        $mid = intdiv($span['from'] + $span['to'], 2);

        return max($mid - $span['from'] + 1, $span['to'] - $mid);
    }

    private function maxBridgeLevels(): int
    {
        return max(
            $this->bridgeLevels($this->leafBridge()),
            $this->bridgeLevels($this->nodeBridge()),
        );
    }

    private function bridgeSpeed(): int
    {
        return max(1, (int) ceil($this->maxBridgeLevels() / self::BRIDGE_TICK_BUDGET));
    }

    private function bridgeTickCount(): int
    {
        return (int) ceil($this->maxBridgeLevels() / $this->bridgeSpeed());
    }

    private function addBridgeReveals(int $startTick): void
    {
        $speed = $this->bridgeSpeed();
        for ($d = 0; $d < $this->bridgeTickCount(); $d++) {
            $cells = array_merge(
                $this->bridgeChunk($this->leafBridge(), $d, $speed),
                $this->bridgeChunk($this->nodeBridge(), $d, $speed),
            );
            if ($cells === []) {
                throw new LogicException('empty bridge tick — schedule is broken');
            }
            $this->addReveal($startTick + $d, $cells);
        }
    }

    /**
     * Bridge cells at level distances d*speed .. d*speed+speed-1 from the
     * span centre, one column per side per level: the bridge grows outward.
     *
     * @param array{row: int, from: int, to: int} $span
     *
     * @return list<array{row: int, col: int, glyph: string, color: string}>
     */
    private function bridgeChunk(array $span, int $d, int $speed): array
    {
        $mid = intdiv($span['from'] + $span['to'], 2);

        $cells = [];
        for ($i = 0; $i < $speed; $i++) {
            $level = $d * $speed + $i;
            foreach ([$mid - $level, $mid + 1 + $level] as $col) {
                if ($col < $span['from'] || $col > $span['to']) {
                    continue;
                }
                $cells[] = $this->cell($span['row'], $col, $this->bridgeDot, 'emerald');
            }
        }

        return $cells;
    }

    /**
     * Canopy arch: spans the outer leaves of both matched subtrees.
     *
     * @return array{from: int, to: int}
     */
    private function archSpan(): array
    {
        return [
            'from' => self::LEFT_INNER_COL - $this->leafSpacing,
            'to' => self::RIGHT_INNER_COL + $this->leafSpacing,
        ];
    }

    private function archTickCount(): int
    {
        return 3;
    }

    private function addArchReveals(int $startTick): void
    {
        $span = $this->archSpan();

        $leftCols = [];
        for ($col = $span['from']; $col < self::CENTRE_COL; $col++) {
            $leftCols[] = $col;
        }
        $rightCols = [];
        for ($col = $span['to']; $col > self::CENTRE_COL; $col--) {
            $rightCols[] = $col;
        }

        $perTick = max(1, (int) ceil(max(count($leftCols), count($rightCols)) / $this->archTickCount()));
        $leftCornerCol = $span['from'];
        $rightCornerCol = $span['to'];
        for ($index = 0; $index < $this->archTickCount(); $index++) {
            $this->addReveal(
                $startTick + $index,
                $this->archCellsFor($index, $perTick, $leftCols, $rightCols, $leftCornerCol, $rightCornerCol),
            );
        }
    }

    /**
     * @param list<int> $leftCols
     * @param list<int> $rightCols
     *
     * @return list<array{row: int, col: int, glyph: string, color: string}>
     */
    private function archCellsFor(
        int $index,
        int $perTick,
        array $leftCols,
        array $rightCols,
        int $leftCornerCol,
        int $rightCornerCol,
    ): array {
        $cells = [];
        foreach (array_slice($leftCols, $index * $perTick, $perTick) as $col) {
            $glyph = $col === $leftCornerCol ? self::GLYPH_ARCH_LEFT : self::GLYPH_DASH;
            $cells[] = $this->cell(self::ROW_CANOPY, $col, $glyph, 'emerald');
        }
        foreach (array_slice($rightCols, $index * $perTick, $perTick) as $col) {
            $glyph = $col === $rightCornerCol ? self::GLYPH_ARCH_RIGHT : self::GLYPH_DASH;
            $cells[] = $this->cell(self::ROW_CANOPY, $col, $glyph, 'emerald');
        }
        if ($index === $this->archTickCount() - 1) {
            $cells[] = $this->cell(self::ROW_CANOPY, self::CENTRE_COL, self::GLYPH_DASH, 'emerald');
        }

        return $cells;
    }

    /**
     * The matched-subtree pattern: the exact emerald geometry duplicated on
     * both sides of the canvas, translated by 22 columns.
     *
     * @return list<array{row: int, col: int, glyph: string}>
     */
    private function subtreePattern(int $col): array
    {
        $cells = [
            ['row' => self::ROW_BRANCHES, 'col' => $col, 'glyph' => $this->nodeDot],
            ['row' => self::ROW_LEAF_BARS, 'col' => $col, 'glyph' => self::GLYPH_JUNCTION],
        ];
        foreach ([-1, 1] as $side) {
            for ($d = 1; $d < $this->leafSpacing; $d++) {
                $cells[] = ['row' => self::ROW_LEAF_BARS, 'col' => $col + $side * $d, 'glyph' => self::GLYPH_DASH];
            }
            $corner = $side < 0 ? self::GLYPH_BRANCH_LEFT : self::GLYPH_BRANCH_RIGHT;
            $cells[] = ['row' => self::ROW_LEAF_BARS, 'col' => $col + $side * $this->leafSpacing, 'glyph' => $corner];
            $cells[] = ['row' => self::ROW_LEAVES, 'col' => $col + $side * $this->leafSpacing, 'glyph' => $this->nodeDot];
        }

        return $cells;
    }

    private function buildEmeraldAnatomy(): void
    {
        $bridge = [];
        foreach ([$this->leafBridge(), $this->nodeBridge()] as $span) {
            for ($col = $span['from']; $col <= $span['to']; $col++) {
                $bridge[] = ['row' => $span['row'], 'col' => $col, 'glyph' => $this->bridgeDot];
            }
        }

        $arch = [];
        $span = $this->archSpan();
        for ($col = $span['from']; $col <= $span['to']; $col++) {
            $glyph = self::GLYPH_DASH;
            if ($col === $span['from']) {
                $glyph = self::GLYPH_ARCH_LEFT;
            }
            if ($col === $span['to']) {
                $glyph = self::GLYPH_ARCH_RIGHT;
            }
            $arch[] = ['row' => self::ROW_CANOPY, 'col' => $col, 'glyph' => $glyph];
        }

        $this->emeraldAnatomy = [
            'left' => $this->subtreePattern(self::LEFT_INNER_COL),
            'right' => $this->subtreePattern(self::RIGHT_INNER_COL),
            'bridge' => $bridge,
            'arch' => $arch,
            'offset' => self::RIGHT_INNER_COL - self::LEFT_INNER_COL,
        ];
    }

    // ============================================================ frame state

    /**
     * Every cell visible at $tick keyed "row:col", with its pulse flag.
     *
     * @return array<string, array{glyph: string, color: string, pulsed: bool}>
     */
    private function visibleCells(int $tick): array
    {
        $grid = [];
        foreach ($this->groundCells as $cell) {
            $grid[$cell['row'] . ':' . $cell['col']] = ['glyph' => $cell['glyph'], 'color' => $cell['color'], 'pulsed' => false];
        }
        foreach ($this->reveals as $reveal) {
            if ($reveal['tick'] > $tick) {
                break;
            }
            foreach ($reveal['cells'] as $cell) {
                $key = $cell['row'] . ':' . $cell['col'];
                if (isset($grid[$key])) {
                    throw new LogicException("cell $key scheduled twice (also at tick {$reveal['tick']})");
                }
                $grid[$key] = [
                    'glyph' => $cell['glyph'],
                    'color' => $cell['color'],
                    'pulsed' => $reveal['tick'] === $tick,
                ];
            }
        }

        return $grid;
    }

    /**
     * @return array{row: int, col: int, glyph: string, color: string}
     */
    private function cell(int $row, int $col, string $glyph, string $color): array
    {
        return ['row' => $row, 'col' => $col, 'glyph' => $glyph, 'color' => $color];
    }

    // ============================================================ emit

    /**
     * @param array<string, array{glyph: string, color: string, pulsed: bool}> $grid
     */
    private function emit(array $grid, string $mode): string
    {
        $out = '';
        for ($row = 0; $row < self::ROWS; $row++) {
            $line = '';
            $activeRun = null;
            for ($col = 0; $col < self::COLS; $col++) {
                $cell = $grid[$row . ':' . $col] ?? null;
                if ($cell === null) {
                    $line .= ' ';
                    continue;
                }
                if ($mode !== self::MODE_PLAIN) {
                    $run = $cell['color'] . ($cell['pulsed'] ? '!' : '');
                    if ($run !== $activeRun) {
                        $line .= $this->colorEscape($mode, $cell['color'], $cell['pulsed']);
                        $activeRun = $run;
                    }
                }
                $line .= $cell['glyph'];
            }
            if ($mode !== self::MODE_PLAIN && $activeRun !== null) {
                $line .= "\e[0m";
            }
            $out .= $line . "\n";
        }

        return $out;
    }

    private function colorEscape(string $mode, string $colorKey, bool $pulsed): string
    {
        $rgb = $this->palette()[$colorKey];
        if ($pulsed) {
            $rgb = $this->towardWhite($rgb, self::PULSE_TOWARD_WHITE);
        }

        if ($mode === self::MODE_256) {
            return "\e[38;5;" . $this->nearest256($rgb) . 'm';
        }

        return "\e[38;2;{$rgb[0]};{$rgb[1]};{$rgb[2]}m";
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    private function palette(): array
    {
        return [
            'bark' => self::BARK_RGB,
            'emerald' => self::EMERALD_RGB,
            'slate' => self::SLATE_RGB,
        ];
    }

    /**
     * @param array{0: int, 1: int, 2: int} $rgb
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function towardWhite(array $rgb, float $f): array
    {
        return array_map(
            static fn (int $v): int => (int) max(0, min(255, round($v + (255 - $v) * $f))),
            $rgb,
        );
    }

    /**
     * Nearest xterm-256 cube colour (indices 16..231) over the 6-step ramp.
     *
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private function nearest256(array $rgb): int
    {
        $ramp = [0, 95, 135, 175, 215, 255];
        $index = static function (int $v) use ($ramp): int {
            $best = 0;
            foreach ($ramp as $i => $step) {
                if (abs($v - $step) < abs($v - $ramp[$best])) {
                    $best = $i;
                }
            }

            return $best;
        };

        return 16 + 36 * $index($rgb[0]) + 6 * $index($rgb[1]) + $index($rgb[2]);
    }
}
