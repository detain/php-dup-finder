<?php
declare(strict_types=1);

namespace Phpdup\Scanning;

use Phpdup\Extraction\Block;

/**
 * Item 5.1 — run-scoped repo/package/scope labels for scanned blocks.
 *
 * Multi-repo output used to double-penalize cross-repo clones because
 * nothing in the pipeline knew which repository a block came from. The
 * doc letter names two markers: "resolve each file's nearest
 * composer.json and .git to a package and repo label". The original
 * honest fact was the scan root alone; this class now resolves the
 * markers INSIDE the root boundary and keeps the root as the fallback:
 *
 *  - repo label: basename of the nearest directory at or above the
 *    file (walking up to, and including, the matching scan root) that
 *    carries a `.git` — directory form OR file form (submodule /
 *    linked worktree). No `.git` inside the boundary → basename of
 *    the longest matching root, the legacy word, byte-identical to
 *    {@see self::labelOf()}.
 *  - package label: the 'name' of the nearest composer.json at or
 *    above the file (again bounded at the root); a manifest with no
 *    usable name degrades to the file's repo label.
 *
 * The boundary law is deliberate and mirrors VendorMarker's M-1 cure:
 * markers ABOVE the scan root are the operator's machine layout, not
 * a project shape the analysis should report. A composer.json or .git
 * sitting in a parent of every root (the shared-checkout-parent case)
 * must not collapse distinct roots into one label — the roots the
 * operator chose to scan ARE the repo units of the run.
 *
 * Labels are RUN CONTEXT, not content facts, and this class treats
 * them that way: every block is stamped fresh from the current
 * config paths on every run, in both consumption funnels
 * (PreprocessStage for blocks, ReportStage for cluster members — the
 * latter covers cluster-cache replay, whose cached members would
 * otherwise carry the roots of the run that saved them). Nothing
 * label-shaped is ever persisted, which is why no IndexStore
 * PARSER_VERSION bump was needed: stale `.idx` blobs restore with
 * `''` defaults that the stamping pass always overwrites before any
 * consumer reads them.
 *
 * Fail-open law (skip-with-note, FileScanner/VendorMarker lineage): an
 * unreadable or corrupt composer.json does not kill the run — files
 * under it lose ONLY the package leg (the repo leg stands, the name
 * degrades to the repo label) and the event surfaces at most once per
 * directory via {@see self::notes()}. Absent manifests are the normal
 * case and earn no note.
 *
 * The static trio ({@see self::labelOf()}, {@see self::stamp()},
 * {@see self::stampAll()}) is the frozen legacy root-only contract —
 * RepoLabelsTest and IndexStoreStaleLabelsTest pin it — and now
 * delegates to an instance built from the given roots, so the word
 * computed for marker-free trees is the SAME word the instance
 * computes. Per-run callers construct one resolver via
 * {@see self::forRoots()} (VendorMarker posture: root map locked at
 * construction, per-directory memo, notes channel).
 */
final class RepoLabels
{
    /** Scope value when the run had a single scan root. */
    public const SCOPE_SINGLE = 'single';

    /**
     * Composer package-name charset: byte-identical to
     * PlacementAdvisor::PACKAGE_NAME_PATTERN (output-046) — names
     * arrive from the UNTRUSTED tree's composer.json, so anything
     * outside composer's own admitted charset (control bytes, spaces,
     * tabs) is dropped as unnameable, never rewritten into the label.
     */
    private const PACKAGE_NAME_PATTERN = '#^[A-Za-z0-9._~/-]+$#';

    /** Byte ceiling for a package label — hostile/oversized names degrade like unnameable ones. */
    private const PACKAGE_NAME_MAX_BYTES = 256;

    /** Number of roots as passed — drives the legacy scope word law (≤1 root → 'single'). */
    private int $rootCount = 0;

    /**
     * Normalized roots in given order: rtrimmed path and its match
     * prefix. Empty/slash-only entries are skipped exactly as
     * {@see self::labelOf()} skips them.
     *
     * @var list<array{path:string, prefix:string, label:string}>
     */
    private array $roots = [];

    /** @var array<string, array{repo:string, package:string}> per-directory memo (dirname => resolved labels) */
    private array $dirMemo = [];

    /** @var list<string> fail-open notes, in discovery order, deduplicated */
    private array $notes = [];

    /**
     * @param list<string> $roots raw configured scan roots (same form
     *                            PathCanonical strips: literal, as passed)
     */
    public function __construct(array $roots)
    {
        $this->rootCount = count($roots);
        foreach ($roots as $root) {
            $trimmed = rtrim($root, '/');
            if ($trimmed === '') {
                continue;
            }
            $this->roots[] = ['path' => $trimmed, 'prefix' => $trimmed . '/', 'label' => basename($trimmed)];
        }
    }

    /**
     * Build from the run's roots — the VendorMarker-shaped entry point
     * every per-run caller uses (ReportStage, PreprocessStage).
     *
     * @param list<string> $roots
     */
    public static function forRoots(array $roots): self
    {
        return new self($roots);
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Repo label of a scanned file: nearest in-boundary `.git` carrier,
     * else basename of the longest matching scan root, '' when no root
     * matches. Static form of {@see self::repoOf()}; on marker-free
     * trees (every legacy pin) it answers the identical word.
     *
     * @param list<string> $roots raw configured scan roots (same form
     *                            PathCanonical strips: literal, as passed)
     */
    public static function labelOf(string $file, array $roots): string
    {
        return (new self($roots))->repoOf($file);
    }

    /**
     * Stamp $block's repo + scope from the current run's roots. Called
     * on every block of every run — cache-restored and freshly
     * extracted alike — so a label observable anywhere downstream was
     * computed from THIS run's configuration.
     *
     * @param list<string> $roots
     */
    public static function stamp(Block $block, array $roots): void
    {
        (new self($roots))->stampBlock($block);
    }

    /**
     * @param iterable<Block>       $blocks
     * @param list<string>          $roots
     */
    public static function stampAll(iterable $blocks, array $roots): void
    {
        $labels = new self($roots);
        foreach ($blocks as $block) {
            $labels->stampBlock($block);
        }
    }

    /**
     * Repo label for one file path: the nearest in-boundary `.git`
     * carrier if any, else the longest matching root's basename, else
     * the file-as-root exact match, else '' (honest no-match).
     */
    public function repoOf(string $file): string
    {
        return $this->resolve($file)['repo'];
    }

    /**
     * Package label for one file path: nearest in-boundary
     * composer.json name (charset-guarded), degrading to the file's
     * repo label, and to '' only when even the repo label is absent.
     */
    public function packageOf(string $file): string
    {
        return $this->resolve($file)['package'];
    }

    /**
     * Stamp $block's repo + package + scope from THIS run's roots (the
     * restamp law: whatever a deserialized blob carried is overwritten
     * here first). Scope word law is unchanged from the legacy stamp:
     * a one-root (or root-less) run says 'single', a multi-root run
     * says the repo label. Named apart from the static legacy pair
     * because PHP forbids same-named static/instance methods.
     */
    public function stampBlock(Block $block): void
    {
        $labels          = $this->resolve($block->file);
        $block->repo     = $labels['repo'];
        $block->package  = $labels['package'];
        $block->scope    = $this->rootCount <= 1 ? self::SCOPE_SINGLE : $labels['repo'];
    }

    /** @param iterable<Block> $blocks */
    public function stampAllBlocks(iterable $blocks): void
    {
        foreach ($blocks as $block) {
            $this->stampBlock($block);
        }
    }

    /**
     * @return array{repo:string, package:string}
     */
    private function resolve(string $file): array
    {
        // File-as-root first: it is identity by EXACT path, not by
        // directory, and must never share (or poison) the directory
        // memo with sibling paths that are not roots themselves.
        foreach ($this->roots as $root) {
            if ($file === $root['path']) {
                return ['repo' => $root['label'], 'package' => $root['label']];
            }
        }
        $dir = dirname($file);
        if (isset($this->dirMemo[$dir])) {
            return $this->dirMemo[$dir];
        }
        return $this->dirMemo[$dir] = $this->computeLabels($file, $dir);
    }

    /**
     * One bounded upward walk per directory answers both legs. The
     * walk visits dirname(file), dirname(dirname(file)), ... and
     * STOPS at the matched root (inclusive): markers above it are
     * never consulted (boundary law, see class docblock).
     *
     * @return array{repo:string, package:string}
     */
    private function computeLabels(string $file, string $dir): array
    {
        $match = $this->matchRoot($dir);
        if ($match === null) {
            // Nothing under any configured root (exact file-as-root
            // cases were already answered in resolve()): the honest
            // '' pair, same as legacy labelOf's no-match fallback.
            return ['repo' => '', 'package' => ''];
        }

        $gitRepo     = '';
        $composerPkg = '';
        // Composer leg states: seeking → found ($composerPkg) or
        // unusable (first manifest encountered was corrupt/nameless —
        // nearest-composer law says degrade to the repo word, NOT
        // substitute an outer manifest further up the chain).
        $composerSeeking = true;
        $current         = $dir;
        $rootPrefix      = $match['prefix'];
        while (true) {
            if ($gitRepo === '' && (is_dir($current . '/.git') || is_file($current . '/.git'))) {
                $gitRepo = basename($current);
            }
            if ($composerSeeking) {
                $name = $this->manifestName($current . '/composer.json');
                if ($name !== null) {
                    $composerSeeking = false;
                    $composerPkg     = $name; // '' = found-unusable
                }
            }
            if ($gitRepo !== '' && (!$composerSeeking || $composerPkg !== '')) {
                break;
            }
            if ($current === $match['path']) {
                break; // boundary: never walk above the matched root
            }
            $parent = dirname($current);
            if ($parent === $current || strlen($rootPrefix) >= strlen($current) || !str_starts_with($current, $rootPrefix)) {
                break;
            }
            $current = $parent;
        }

        $repo = $gitRepo !== '' ? $gitRepo : $match['label'];

        // The composer leg only counts if a USABLE manifest was found;
        // manifestName() distinguishes 'absent' (null, keep walking) from
        // 'found but unusable' ('' + note, degrade now — the chain below
        // it cannot hold a better name for THIS file than its own).
        $package = $composerPkg !== '' ? $composerPkg : $repo;

        return ['repo' => $repo, 'package' => $package];
    }

    /**
     * Read a composer.json 'name' honestly:
     *   null  — no manifest at this directory (keep walking up)
     *   ''    — manifest present but unusable (unreadable/corrupt/
     *           nameless/hostile charset): note once, degrade
     *   string — the sanitized package name.
     */
    private function manifestName(string $manifest): ?string
    {
        if (!is_file($manifest)) {
            return null;
        }
        $raw = @file_get_contents($manifest);
        if ($raw === false) {
            $this->note('repo labels: unreadable composer.json at ' . dirname($manifest) . ' — package label degraded for files under it');
            return '';
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->note('repo labels: corrupt composer.json at ' . dirname($manifest) . ' — package label degraded for files under it');
            return '';
        }
        $name = $data['name'] ?? null;
        if (!is_string($name) || $name === '' || strlen($name) > self::PACKAGE_NAME_MAX_BYTES || preg_match(self::PACKAGE_NAME_PATTERN, $name) !== 1) {
            $this->note('repo labels: composer.json at ' . dirname($manifest) . ' carries no usable package name — files under it label by repo');
            return '';
        }
        return $name;
    }

    /**
     * Longest matching root for a directory prefix (the same
     * longest-match-wins law labelOf always had).
     *
     * @return array{path:string, prefix:string, label:string}|null
     */
    private function matchRoot(string $dir): ?array
    {
        $best    = null;
        $bestLen = 0;
        foreach ($this->roots as $root) {
            // A directory root matches itself or any descendant path —
            // the same longest-match-wins word legacy labelOf produced
            // from a file-prefix test (file's dirname is the root path
            // exactly when the file sits directly under the root).
            $hit = $dir === $root['path'] || str_starts_with($dir, $root['prefix']);
            if (!$hit) {
                continue;
            }
            $len = strlen($root['path']);
            if ($best === null || $len > $bestLen) {
                $best    = $root;
                $bestLen = $len;
            }
        }
        return $best;
    }

    private function note(string $message): void
    {
        if (!in_array($message, $this->notes, true)) {
            $this->notes[] = $message;
        }
    }
}
