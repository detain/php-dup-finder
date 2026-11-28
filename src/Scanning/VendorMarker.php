<?php
declare(strict_types=1);

namespace Phpdup\Scanning;

use Phpdup\Extraction\Block;

/**
 * Item 5.2 (W2b) — vendored third-party copy marker.
 *
 * The deep any-depth vendor/node_modules defaults (and the `.gitignore`
 * leg beside them)
 * keep stock package trees out of the scan; what survives any exclusion
 * stance is the vendored copy committed OUTSIDE a vendor/ dir — the
 * nusoap-in-include/ pattern (S3-23: nusoap shipped twice; S1-38: a
 * workerman demo tree copied twice). Those copies are third-party code:
 * first-party duplication analysis must not spend a user's attention
 * (or a CI gate's budget) on package clones the user never wrote.
 *
 * Three signals, doc letter in order (composer.lock evidence, the
 * nested-vendor path the item opens with, the known-upstream-header
 * OR-leg), cheapest first, any one marks the file:
 *
 *  1. `vendor_dir`        — a DIRECTORY segment named `vendor` anywhere
 *     in the path (never the basename: `Vendor.php` is a first-party
 *     class name, a `vendor/` dir is the universal copy convention).
 *  2. `composer_lock_path` — the file sits under a scan root whose
 *     composer.lock declares a package, and the file's DIRECTORY
 *     segments spell that package name: full `ns/short` adjacency, or
 *     the bare short name as a standalone dir segment (the
 *     `include/nusoap/` copy shape). A basename-only collision
 *     (`src/nusoap.php` written by hand) never marks; the doc's
 *     motivating copies are package-trees, not filename echoes.
 *  3. `upstream_header`   — the first bytes of the file carry a known
 *     upstream copyright/doc header (the KNOWN_HEADERS table below).
 *     Full distinctive phrases only — a first-party file that merely
 *     mentions a package (`use PHPMailer\...`, `require 'nusoap.php'`)
 *     must NOT be marked.
 *
 * Posture: detection is ALWAYS-ON (the doc says "detect ... so they go
 * into their own section" — there is nothing to opt into for a signal
 * that changes no default output). What the marks OBSERVE is demand-
 * gated: the demotion + `summary.vendored_duplicates` section fire only
 * under an armed `--scope` run (info-doc §5.2 ruling: "auto-demote
 * vendored↔vendored under --scope"), so default runs — every golden,
 * every CI smoke leg — stay byte-identical and no payload key opens
 * unasked (conditional-key law, MINE-D §4).
 *
 * Stamps are RUN CONTEXT, exactly like {@see RepoLabels}: `Block::$vendored`
 * is re-derived from the current roots on every run in both consumption
 * funnels, and a cached blob's stale mark is always overwritten before
 * any consumer reads one — which is why no IndexStore/ClusterCache key
 * binds this class (and no header peek is re-paid beyond the instance
 * memo). The composer.lock map lives per root: a package declared by one
 * root's lock says nothing about another root's tree.
 *
 * Fail-open law (skip-with-note, FileScanner lineage): an unreadable or
 * corrupt composer.lock does not kill the run — the affected root loses
 * ONLY its lock-path leg and the event surfaces once via
 * {@see self::notes()}; an unreadable source file simply yields no
 * header signal. Absent locks are not an error and earn no note.
 */
final class VendorMarker
{
    public const SIGNAL_VENDOR_DIR = 'vendor_dir';
    public const SIGNAL_LOCK_PATH  = 'composer_lock_path';
    public const SIGNAL_HEADER     = 'upstream_header';

    /** Bytes of a file head the header leg may look at (table anchors live in the file header). */
    private const HEADER_PEEK_BYTES = 4096;

    /**
     * Known upstream headers — family label => case-insensitive anchor
     * phrase. Deliberately small: the doc's two motivating families
     * (nusoap, workerman) plus the two most-classically-vendored PHP
     * package families (phpmailer, tcpdf). Every anchor is a full
     * phrase an upstream shipped IN its own files, not a bare package
     * word first-party code might legitimately contain.
     *
     * @var array<string, list<string>>
     */
    private const KNOWN_HEADERS = [
        'nusoap'    => ['NuSOAP - Web Services Toolkit for PHP'],
        'workerman' => ['This file is part of the workerman framework'],
        'phpmailer' => ['PHPMailer - PHP email creation and transport', 'PHPMailer - The PHP mailer'],
        'tcpdf'     => ['Nicola Asuni - Tecnick.com', 'TCPDF (http://www.tcpdf.org)'],
    ];

    /**
     * Per-root lock maps: longest-match-wins scan root prefix, plus the
     * lower-cased package names it declares, split into [ns, short]
     * (ns null for single-word names).
     *
     * @var list<array{prefix:string, packages:list<array{ns:?string, short:string, full:string}>}>
     */
    private array $rootLocks = [];

    /** @var list<string> fail-open notes, in discovery order, deduplicated */
    private array $notes = [];

    /** @var array<string, array{signal:string, detail:string}> per-file memo */
    private array $fileMemo = [];

    /**
     * @param list<string> $roots raw configured scan roots (same literal
     *                            form RepoLabels consumes; roots naming a
     *                            single file carry no lock and are skipped)
     */
    public function __construct(array $roots)
    {
        foreach ($roots as $root) {
            $trimmed = rtrim($root, '/');
            if ($trimmed === '' || !is_dir($trimmed)) {
                continue;
            }
            $lockPath = $trimmed . '/composer.lock';
            if (!is_file($lockPath)) {
                continue;
            }
            $raw = @file_get_contents($lockPath);
            if ($raw === false) {
                $this->note('vendor marker: unreadable composer.lock at ' . $trimmed . ' — package-name path leg disabled for this root');
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $this->note('vendor marker: corrupt composer.lock at ' . $trimmed . ' — package-name path leg disabled for this root');
                continue;
            }
            $packages = [];
            foreach (['packages', 'packages-dev'] as $lane) {
                foreach (is_array($data[$lane] ?? null) ? $data[$lane] : [] as $pkg) {
                    $name = is_array($pkg) ? ($pkg['name'] ?? null) : null;
                    if (!is_string($name) || $name === '') {
                        continue;
                    }
                    $lower   = strtolower($name);
                    $slash   = strpos($lower, '/');
                    $packages[] = $slash === false
                        ? ['ns' => null, 'short' => $lower, 'full' => $lower]
                        : ['ns' => substr($lower, 0, $slash), 'short' => substr($lower, $slash + 1), 'full' => $lower];
                }
            }
            if ($packages !== []) {
                // The whole leg is case-insensitive (composer/package
                // naming convention): store the lower-cased prefix so
                // the comparison below matches the lower-cased file.
                $this->rootLocks[] = ['prefix' => strtolower($trimmed) . '/', 'packages' => $packages];
            }
        }
    }

    /**
     * Build from the run's roots — the RepoLabels-shaped entry point.
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
     * Vendored signal for a file path: ['signal' => '', 'detail' => '']
     * when the file is first-party. Deterministic, instance-memoized;
     * the header leg peeks at most HEADER_PEEK_BYTES once per file.
     *
     * @return array{signal:string, detail:string}
     */
    public function describe(string $file): array
    {
        if (isset($this->fileMemo[$file])) {
            return $this->fileMemo[$file];
        }
        $verdict = $this->compute($file);
        return $this->fileMemo[$file] = $verdict;
    }

    public function isVendored(string $file): bool
    {
        return $this->describe($file)['signal'] !== '';
    }

    /** `signal[:detail]` label for report rows ('' for first-party). */
    public function describeLabel(string $file): string
    {
        $v = $this->describe($file);
        if ($v['signal'] === '') {
            return '';
        }
        return $v['detail'] === '' ? $v['signal'] : $v['signal'] . ':' . $v['detail'];
    }

    /**
     * Stamp $block's $vendored from THIS run's roots (the restamp law:
     * whatever a deserialized blob carried is overwritten here first).
     */
    public function stamp(Block $block): void
    {
        $block->vendored = $this->isVendored($block->file);
    }

    /** @param iterable<Block> $blocks */
    public function stampAll(iterable $blocks): void
    {
        foreach ($blocks as $block) {
            $this->stamp($block);
        }
    }

    /** @return array{signal:string, detail:string} */
    private function compute(string $file): array
    {
        // Segments of the file's DIRECTORY chain only (basename never
        // counts — see the class docblock); lower-cased for the
        // case-insensitive composer/package convention.
        $dir = strtolower(str_replace('\\', '/', dirname($file)));
        $segments = $dir === '' || $dir === '.' ? [] : array_values(array_filter(explode('/', $dir), static fn(string $s): bool => $s !== ''));

        // Leg 1: nested vendor/ tree at any depth.
        foreach ($segments as $seg) {
            if ($seg === 'vendor') {
                return ['signal' => self::SIGNAL_VENDOR_DIR, 'detail' => ''];
            }
        }

        // Leg 2: composer.lock package-name path map, per declaring root
        // (longest prefix wins — same root-selection law as RepoLabels).
        //
        // M-1 (r1 cure): the matchable segments are those BELOW the
        // matched root boundary only. An ancestor segment of the
        // absolute path — the checkout directory itself included
        // ('~/www/mail' holding a lock that declares someone/mail) —
        // is the operator's machine layout, not a package shape the
        // project vendored; matching it would mark the WHOLE tree and
        // empty an armed --scope report vacuously. The doc archetype
        // (nusoap committed at include/nusoap/ UNDER the root) needs
        // nothing above the boundary, so the cure costs the leg zero
        // real detections.
        $lowerFile = strtolower(str_replace('\\', '/', $file));
        $best = null;
        foreach ($this->rootLocks as $entry) {
            if (str_starts_with($lowerFile, $entry['prefix'])) {
                if ($best === null || strlen($entry['prefix']) > strlen($best['prefix'])) {
                    $best = $entry;
                }
            }
        }
        if ($best !== null) {
            // $dir is the lower-cased normalized dirname; the prefix is
            // '<root>/' so substr at strlen-1 keeps the leading '/' of
            // the remainder — the root's own basename drops out.
            $rel     = substr($dir, strlen($best['prefix']) - 1);
            $relSegs = $rel === '' || $rel === '/' ? [] : array_values(array_filter(explode('/', $rel), static fn(string $s): bool => $s !== ''));
            foreach ($best['packages'] as $pkg) {
                if ($pkg['ns'] !== null && self::hasAdjacentSegments($relSegs, [$pkg['ns'], $pkg['short']])) {
                    return ['signal' => self::SIGNAL_LOCK_PATH, 'detail' => $pkg['full']];
                }
                if (in_array($pkg['short'], $relSegs, true)) {
                    return ['signal' => self::SIGNAL_LOCK_PATH, 'detail' => $pkg['full']];
                }
            }
        }

        // Leg 3: known upstream header, bounded first-bytes peek.
        $head = self::peek($file);
        if ($head !== '') {
            $lowerHead = strtolower($head);
            foreach (self::KNOWN_HEADERS as $family => $anchors) {
                foreach ($anchors as $anchor) {
                    if (str_contains($lowerHead, strtolower($anchor))) {
                        return ['signal' => self::SIGNAL_HEADER, 'detail' => $family];
                    }
                }
            }
        }

        return ['signal' => '', 'detail' => ''];
    }

    /**
     * @param list<string> $segments
     * @param non-empty-list<string> $needle
     */
    private static function hasAdjacentSegments(array $segments, array $needle): bool
    {
        $n = count($segments) - count($needle);
        for ($i = 0; $i <= $n; $i++) {
            if (array_slice($segments, $i, count($needle)) === $needle) {
                return true;
            }
        }
        return false;
    }

    private static function peek(string $file): string
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return '';
        }
        try {
            $head = fread($fh, self::HEADER_PEEK_BYTES);
            return $head === false ? '' : $head;
        } finally {
            fclose($fh);
        }
    }

    private function note(string $message): void
    {
        if (!in_array($message, $this->notes, true)) {
            $this->notes[] = $message;
        }
    }
}
