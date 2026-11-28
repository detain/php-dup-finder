<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\HoleCoverage;
use Phpdup\Refactor\PlacementAdvisor;
use Phpdup\Util\ControlText;
use Phpdup\Util\CountPhrase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Cli\Banner;
use SugarCraft\Cli\Section;
use SugarCraft\Cli\StatusLine;
use SugarCraft\Cli\Theme;
use SugarCraft\Style\Align;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;
use SugarCraft\Style\Table\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders the report to a terminal using the SugarCraft TUI stack
 * (lipgloss-style styling + fang-style banners and section headers).
 *
 * When the output is not decorated (Symfony's --no-ansi or output piped
 * to a non-TTY), we switch to {@see Theme::plain()} so the same
 * code path produces clean, ANSI-free text.
 *
 * output-046 boundary law: every scanned-derived string (member paths and
 * names, signatures, hole evidence, pattern tags, composer host packages)
 * is scrubbed through the shared {@see ControlText} substitute arm before
 * it reaches writeln(). Theme::plain() neutralizes THIS file's own Style
 * objects — nothing neutralizes an ESC byte that rode in inside a scanned
 * filename, and OSC window-title/clipboard payloads drive the terminal of
 * whoever cats the output even under --no-ansi. Clean text stays
 * byte-identical, so the scrub costs nothing on honest trees.
 */
final class CliReporter
{
    public const VERBOSITY_FULL         = 'full';
    public const VERBOSITY_CLUSTERS     = 'clusters';     // banner + per-cluster one-liner list, no inner details
    public const VERBOSITY_SUMMARY_ONLY = 'summary-only'; // banner + final summary only

    public function __construct(
        private readonly string $verbosity = self::VERBOSITY_FULL,
    ) {
        if (!in_array($verbosity, [self::VERBOSITY_FULL, self::VERBOSITY_CLUSTERS, self::VERBOSITY_SUMMARY_ONLY], true)) {
            throw new \InvalidArgumentException("Invalid CliReporter verbosity: {$verbosity}");
        }
    }

    public function render(Report $report, OutputInterface $out, int $limit = 50): void
    {
        $decorated = $out->isDecorated();
        $theme = $decorated ? Theme::ansi() : Theme::plain();
        $width = self::terminalWidth();
        $clusters = $report->clusters;
        // One advisor per render so composer manifests are read once (item 5.3).
        $advisor = new PlacementAdvisor($report->config->paths);

        // output-054: the banner and the trailer print the same totals, so
        // they are computed once here and handed to both sites — a big
        // report no longer pays the O(clusters) walks twice per render.
        $clusterCount     = count($clusters);
        $duplicated       = $report->totalDuplicatedLines();
        $totalImpact      = array_sum(array_map(fn(Cluster $c) => $c->impact, $clusters));

        $out->writeln('');
        $out->writeln(Banner::title('phpdup', sprintf(
            '%d files · %d blocks · %d clusters · %d duplicated lines · %d total impact',
            $report->files,
            $report->blocks,
            $clusterCount,
            $duplicated,
            $totalImpact,
        ), $theme, Border::rounded()));
        $out->writeln('');

        if (!$clusters) {
            // 0.6: "clean." alone was a lie by omission when much of the
            // corpus never entered the comparison at all (zero-block files
            // emitted no rows, parse errors only bumped a number nobody
            // could attribute). Uncomparable files now surface in the
            // verdict itself.
            $uncomparable = ($report->skippedFiles ?? 0) + $report->parseErrors;
            if ($uncomparable > 0) {
                $out->writeln(StatusLine::warn(sprintf(
                    'no clusters above threshold — but %d of %d files produced nothing comparable.',
                    $uncomparable,
                    $report->files,
                ), $theme));
            } else {
                $out->writeln(StatusLine::success('no clusters above threshold — clean.', $theme));
            }
            $this->renderFileAccounting($report, $theme, $out);
            // Item 4.4: an accepted-only report is not a silent report —
            // the appendix names every suppression even when the main
            // payload is empty.
            $this->renderBaselineAppendix($report, $theme, $out);
            // Item 2.2: the clean path is exactly where corpus awareness
            // earns its keep — "no clusters" says more when the rollup
            // line next to it states how much was comparable at all.
            $this->renderRollups($report, $theme, $out);
            // Item 5.2 (W2b deferred leg, landed here in the rollup train):
            // the scope demotion is trailer-visible too.
            $this->renderVendored($report, $theme, $out);
            // Item 5.1: the scope-labels census rides the clean path with
            // its trailer siblings.
            $this->renderScopeCounts($report, $theme, $out);
            return;
        }

        if ($this->verbosity === self::VERBOSITY_SUMMARY_ONLY) {
            $this->renderTrailer($report, $clusterCount, $duplicated, $totalImpact, $theme, $out);
            return;
        }

        $shown = min($limit, count($clusters));
        $out->writeln(StatusLine::info(
            sprintf('%d cluster(s); showing top %d (sorted by impact)', count($clusters), $shown),
            $theme,
        ));

        if ($this->verbosity === self::VERBOSITY_CLUSTERS) {
            $this->renderClusterList(array_slice($clusters, 0, $shown), $theme, $out);
            $this->renderTrailer($report, $clusterCount, $duplicated, $totalImpact, $theme, $out);
            return;
        }

        for ($i = 0; $i < $shown; $i++) {
            $this->renderCluster($i + 1, $clusters[$i], $report->config, $advisor, $theme, $width, $decorated, $out);
        }

        $this->renderTrailer($report, $clusterCount, $duplicated, $totalImpact, $theme, $out);
    }

    /**
     * Trailing summary line — kept identical across verbosity levels so
     * scripts grepping for the totals work regardless of mode.
     *
     * output-054: the totals arrive pre-computed from render() — the trailer
     * no longer re-walks every cluster to re-derive what the banner printed.
     */
    private function renderTrailer(Report $report, int $clusterCount, int $duplicated, int $totalImpact, Theme $theme, OutputInterface $out): void
    {
        $out->writeln('');
        $out->writeln(StatusLine::success(sprintf(
            'summary  %d clusters · %d duplicated lines · %d total impact',
            $clusterCount,
            $duplicated,
            $totalImpact,
        ), $theme));
        $this->renderFileAccounting($report, $theme, $out);
        $this->renderBaselineAppendix($report, $theme, $out);
        $this->renderRollups($report, $theme, $out);
        $this->renderVendored($report, $theme, $out);
        // Item 5.1: the scope-labels census line, same trailer posture.
        $this->renderScopeCounts($report, $theme, $out);
    }

    /**
     * Item 4.4 APPLY half: the baseline appendix. Accepted findings are
     * SUPPRESSED FROM THE MAIN REPORT — this surface makes the
     * suppression visible: what was dropped, who accepted it (registry
     * file / @phpdup-ignore directive / phpdup.json list), when, where
     * (member files), and whether it RESURFACED after content drift.
     * Renders only when decisions were in play; the default report is
     * byte-unchanged.
     */
    private function renderBaselineAppendix(Report $report, Theme $theme, OutputInterface $out): void
    {
        $rows = $report->baselineAppendix;
        if ($rows === []) {
            return;
        }

        $accepted = 0;
        $unresolved = 0;
        $resurfaced = 0;
        foreach ($rows as $row) {
            if (($row['status'] ?? null) === BaselineDisposition::STATUS_UNRESOLVED) {
                $unresolved++;
            } elseif (($row['resurfaced'] ?? false) === true) {
                $resurfaced++;
                $accepted++;
            } else {
                $accepted++;
            }
        }

        $out->writeln('');
        $out->writeln(StatusLine::info(sprintf(
            'baseline  %d accepted · %d resurfaced · %d unresolved',
            $accepted,
            $resurfaced,
            $unresolved,
        ), $theme));

        foreach ($rows as $row) {
            // M-2 (E4 r1): note / paths / ids are attacker-influenced in a
            // scanned repo (directive reason text is verbatim source-comment
            // tail; registry files are editable records). Every one rides
            // the file's own ControlText scrub — the same output-046
            // posture the rationale and hole-name legs already follow. The
            // HTML leg escapes; the CLI leg must not print raw.
            $id = self::scrub((string)($row['finding_id'] ?? '?'));
            $status = (string)($row['status'] ?? BaselineDisposition::STATUS_ACCEPTED);
            $source = (string)($row['source'] ?? 'registry');
            $bits = ["  $id  $status ($source"];
            if ($source === 'directive' && isset($row['directive_file'])) {
                $bits[0] .= ', ' . self::scrub((string)$row['directive_file'])
                    . ':' . (int)($row['directive_line'] ?? 0);
            }
            $bits[0] .= ')';

            if (($row['resurfaced'] ?? false) === true) {
                $bits[] = 'RESURFACED (was accepted as ' . self::scrub((string)($row['accepted_id'] ?? '?'))
                    . ' — re-accept or remove the entry)';
            } elseif (($row['absent'] ?? false) === true) {
                $bits[] = 'not found this run';
            } elseif ($status === BaselineDisposition::STATUS_ACCEPTED) {
                $bits[] = sprintf('%d members · impact %d', (int)($row['members'] ?? 0), (int)($row['impact'] ?? 0));
            }

            $files = (array)($row['files'] ?? []);
            if ($files !== []) {
                $shown = array_slice($files, 0, 3);
                $bits[] = implode(', ', array_map(self::scrub(...), array_map('strval', $shown)))
                    . (count($files) > 3 ? sprintf(' (+%d files)', count($files) - 3) : '');
            }
            if (isset($row['note']) && is_string($row['note']) && $row['note'] !== '') {
                $bits[] = '"' . self::scrub($row['note']) . '"';
            }
            if (isset($row['accepted_at']) && is_string($row['accepted_at']) && $status === BaselineDisposition::STATUS_UNRESOLVED) {
                $bits[] = 'since ' . self::scrub($row['accepted_at']);
            }

            $out->writeln(implode('  ', $bits));
        }
    }

    /**
     * Item 2.2 (ROLLUP): one compact corpus-awareness line — how much of
     * the corpus was comparable at all, how many file pairs mirror each
     * other, and how many directory pairs re-implement themselves. Emitted
     * whenever the producer ran the rollup pass (the pipeline Report);
     * legacy library/worker reports carry null and this says nothing —
     * tracked-not-fabricated, the same posture as the file-accounting line
     * above. It rides the trailer, so the one line is identical across all
     * verbosity modes (the trailer law), and only the JSON payload carries
     * the per-file detail.
     */
    private function renderRollups(Report $report, Theme $theme, OutputInterface $out): void
    {
        $rollups = $report->rollups;
        if ($rollups === null) {
            return;
        }
        $totals = $rollups['totals'];
        $out->writeln(StatusLine::info(sprintf(
            'rollups  %d files · %d comparable lines · %d declarations · %d call sites · %d twin file pairs · %d mirrored dirs',
            $totals['files'],
            $totals['comparable_lines'],
            $totals['declarations'],
            $totals['call_sites'],
            $totals['twin_file_pairs'],
            $totals['mirrored_directories'],
        ), $theme));
    }

    /**
     * Item 5.2 (W2b deferred leg — landed in the rollup train, which owns
     * this file): the vendored demotion is a one-shot stderr visibility
     * line and a conditional JSON section; the trailer makes it CLI-
     * visible where the rows went. Honors the stderr line's posture:
     * renders only when clusters were actually demoted — a scope-armed,
     * vendored-free run (zero-shaped section) and an unarmed run print
     * nothing, so default CLI bytes never move.
     */
    private function renderVendored(Report $report, Theme $theme, OutputInterface $out): void
    {
        $vendored = $report->vendoredDuplicates;
        if ($vendored === null || $vendored['clusters'] === 0) {
            return;
        }
        $out->writeln(StatusLine::info(sprintf(
            'vendored  %d clusters · %d lines demoted to the vendored_duplicates section%s',
            $vendored['clusters'],
            $vendored['lines'],
            $vendored['omitted'] > 0 ? sprintf(' · %d beyond the row cap', $vendored['omitted']) : '',
        ), $theme));
    }

    /**
     * Item 5.1 (scope-counts leg): the armed ladder census in one line —
     * the four buckets over the REPORTED cluster set, the same arithmetic
     * summary.scope_counts carries (the 'of N' states the identity
     * denominator). Only armed runs (scope_labels) carry non-null; an
     * unarmed run prints nothing, so default CLI bytes never move — the
     * trailer posture of renderRollups above (a measured all-zero census
     * does print: it is an answer, not an absence).
     */
    private function renderScopeCounts(Report $report, Theme $theme, OutputInterface $out): void
    {
        $counts = $report->scopeCounts;
        if ($counts === null) {
            return;
        }
        $out->writeln(StatusLine::info(sprintf(
            'scope-labels  %d intra-file · %d intra-package · %d cross-package · %d cross-repo (of %d reported clusters)',
            $counts[ClusterScope::LADDER_INTRA_FILE],
            $counts[ClusterScope::LADDER_INTRA_PACKAGE],
            $counts[ClusterScope::LADDER_CROSS_PACKAGE],
            $counts[ClusterScope::LADDER_CROSS_REPO],
            // Explicit four-bucket sum — the output-054 once-per-render
            // guard pins the array-sum helper to the single impact total
            // above; the ladder census states its own visible arity.
            $counts[ClusterScope::LADDER_INTRA_FILE]
                + $counts[ClusterScope::LADDER_INTRA_PACKAGE]
                + $counts[ClusterScope::LADDER_CROSS_PACKAGE]
                + $counts[ClusterScope::LADDER_CROSS_REPO],
        ), $theme));
    }

    /**
     * File-accounting surface (0.6). Emits nothing when the producer never
     * tracked the buckets (legacy `new Report(...)` callers pass nulls);
     * when files skipped extraction, blocks were dropped for exceeding
     * the size ceiling, or files failed to parse, a warn line names the
     * counts, and the per-bucket breakdown `files  N scanned · ...` gives
     * every counter a label with ONE definition each (balance law:
     * scanned = reused + processed + no blocks + parse errors).
     */
    private function renderFileAccounting(Report $report, Theme $theme, OutputInterface $out): void
    {
        if ($report->reusedFiles === null && $report->processedFiles === null && $report->skippedFiles === null) {
            return;
        }
        $notes = [];
        $skipped = $report->skippedFiles ?? 0;
        if ($skipped > 0) {
            $notes[] = CountPhrase::files($skipped) . ' produced no comparable blocks';
        }
        // E2 item 0.6: the mass the size ceiling took silently — printed
        // loud now, at the granularity that escaped (fully dropped parents
        // count once; statements a salvaged parent's window packing gave
        // up on count individually, hence the compound unit noun).
        // Fresh-extraction semantics (cache-reused
        // files do not re-run extraction, so a warm run's count covers
        // what it actually processed); null = untracked producer, an
        // honest nothing-to-say, same posture as the buckets above.
        $oversized = $report->oversizedBlocks ?? 0;
        if ($oversized > 0) {
            $notes[] = sprintf(
                '%d %s exceeded the max block size and %s dropped',
                $oversized,
                $oversized === 1 ? 'block/statement' : 'blocks/statements',
                $oversized === 1 ? 'was' : 'were',
            );
        }
        if ($report->parseErrors > 0) {
            $notes[] = CountPhrase::files($report->parseErrors) . ' failed to parse';
        }
        if ($notes !== []) {
            $out->writeln(StatusLine::warn(implode(' · ', $notes), $theme));
        }
        $out->writeln(StatusLine::info(sprintf(
            'files  %d scanned · %d reused · %d processed · %d no blocks · %d parse errors',
            $report->files,
            $report->reusedFiles ?? 0,
            $report->processedFiles ?? 0,
            $report->skippedFiles ?? 0,
            $report->parseErrors,
        ), $theme));
    }

    /**
     * Compact per-cluster one-liner table for --clusters mode. No
     * member tables, signatures, holes, or pattern chips — just the
     * top-line numbers so users can grep-by-id and pipe into other
     * tools.
     *
     * output-060: rows are collected first and appended through one
     * `Table::rows()` call. The old `$table = $table->row(...)` loop ran on
     * the immutable vendor Table, which clones and spreads ALL prior rows
     * per call — O(R²) when a large `--limit` shows thousands of clusters
     * (harmless at the default 50). One batched append is linear and
     * renders byte-identically (pinned by CliReporterVerbosityTest).
     *
     * @param list<Cluster> $clusters
     */
    private function renderClusterList(array $clusters, Theme $theme, OutputInterface $out): void
    {
        $out->writeln('');
        $rows = [];
        foreach ($clusters as $c) {
            $loc = isset($c->members[0]) ? self::scrub($c->members[0]->location()) : '';
            $rows[] = [
                $c->id,
                (string)$c->size(),
                sprintf('%.2f', $c->similarity),
                (string)$c->impact,
                isset($c->members[0]->kind) ? $c->members[0]->kind : '',
                $loc,
            ];
        }
        $out->writeln(Table::new()
            ->border(Border::rounded())
            ->headers('CLUSTER', 'MEMBERS', 'SIM', 'IMPACT', 'KIND', 'LOCATION')
            ->rows($rows)
            ->render());
    }

    private function renderCluster(int $num, Cluster $c, Config $config, PlacementAdvisor $advisor, Theme $theme, int $width, bool $decorated, OutputInterface $out): void
    {
        $out->writeln('');
        $headLabel = sprintf(
            'Cluster #%d   similarity %.2f   impact %d   members %d%s',
            $num, $c->similarity, $c->impact, $c->size(),
            $c->exact ? '   EXACT' : ''
        );
        $out->writeln(Section::header($headLabel, $theme, leftPad: 2, width: $width));

        // Members table
        $outliers = array_flip($c->outlierMemberIds);
        $memberTable = Table::new()->border(Border::rounded())->headers('LOCATION', 'KIND', 'QUALIFIED NAME');
        foreach ($c->members as $idx => $m) {
            $marker = isset($outliers[$idx]) ? ' ⚠ outlier' : '';
            $memberTable = $memberTable->row(
                self::scrub($m->location()) . $marker,
                $m->kind,
                self::scrub($m->qualifiedName()),
            );
        }
        $out->writeln($memberTable->render());

        if ($c->signature !== null) {
            $out->writeln('');
            $out->writeln(Section::header('Suggested abstraction', $theme, leftPad: 2, width: $width));
            $sigStyle = Style::new()->border(Border::normal())->padding(0, 2);
            if ($decorated) {
                $sigStyle = $sigStyle->foreground(Color::ansi(14)); // bright cyan
            }
            // output-046, line-scoped: the signature's own grammar carries
            // LF between parameter rows (SignatureBuilder:54), so a whole-
            // string substitute would replace its legitimate line breaks.
            // Scrub per line instead — the terminal-driving ESC plus the
            // NUL/CR forgers are removed from every line, and a scanned
            // name cannot smuggle a raw byte onto the box unchanged.
            $out->writeln($sigStyle->render(implode("\n", array_map(
                static fn(string $line): string => self::scrub($line),
                explode("\n", $c->signature),
            ))));
        }

        if ($c->holes) {
            $out->writeln('');
            $out->writeln(Section::header('Holes', $theme, leftPad: 2, width: $width));
            $holesTable = Table::new()
                ->border(Border::rounded())
                ->headers('PARAM', 'TYPE', 'KIND', 'OBSERVED');
            foreach ($c->holes as $h) {
                $holesTable = $holesTable->row(
                    self::scrub($h->suggestedName),
                    self::scrub($h->inferredType),
                    $h->kind,
                    $this->summarizeObserved($h->observedValues, 60, $h->kind, $h->degraded),
                );
            }
            $out->writeln($holesTable->render());

            // Item 5.4 honesty notes — silent unless there is evidence to
            // report: a hole whose value set contains a structural gap, and
            // a template whose hole fraction exceeds the operator's stance.
            $gapped = 0;
            foreach ($c->holes as $h) {
                if ($h->degraded) {
                    $gapped++;
                }
            }
            if ($gapped > 0) {
                $note = sprintf(
                    '%d hole(s) had members with no node at the hole path — shown as (gap); the signature rests on partial evidence.',
                    $gapped,
                );
                $out->writeln('  ' . ($decorated ? $theme->muted->render($note) : $note));
            }
            $coverage = HoleCoverage::coverage($c);
            if ($coverage !== null && $coverage > $config->maxHoleCoverage) {
                $span = HoleCoverage::fragmentSpan($c);
                $out->writeln(sprintf(
                    '  re-expressed: %.0f%% of the generalized template is holes (> --max-hole-coverage %.2f).%s',
                    $coverage * 100,
                    $config->maxHoleCoverage,
                    $span !== null
                        ? sprintf(' Extractable: top-level statements %d-%d of the body.', $span['start'] + 1, $span['start'] + $span['length'])
                        : ' No hole-free top-level statement run remains.',
                ));
            }
        }

        // Item 5.3: placement advice — where the extraction belongs, said
        // even for zero-hole clusters (a verbatim 'move' still needs a host).
        // output-046: the rationale interpolates scanned namespaces and root
        // paths, and the host list rides keys from the untrusted tree's
        // composer.json (PlacementAdvisor already gates those to composer's
        // charset; the boundary scrub is the second lock).
        $placement = $advisor->advise($c);
        $whereLine = sprintf(
            'placement: %s — %s',
            $placement['shape'],
            self::scrub($placement['rationale']),
        );
        if ($placement['host_packages'] !== null && $placement['host_packages'] !== []) {
            $whereLine .= ' [shared host: ' . implode(', ', array_map(
                static fn(string $p): string => self::scrub($p),
                $placement['host_packages'],
            )) . ']';
        }
        $out->writeln('');
        $out->writeln('  ' . ($decorated ? $theme->muted->render($whereLine) : $whereLine));

        if ($c->patternTags) {
            $out->writeln('');
            $out->writeln('  ' . $this->renderTags($c->patternTags, $theme, $decorated));
        }

        if ($c->architecturalFindings) {
            $out->writeln('');
            $out->writeln(Section::header('Architectural notes', $theme, leftPad: 2, width: $width));
            foreach ($c->architecturalFindings as $f) {
                $out->writeln(sprintf(
                    '  [%s] %s — %s',
                    strtoupper($f->severity),
                    $f->code,
                    $f->message,
                ));
                if ($f->suggestion !== null) {
                    $out->writeln('       ↳ ' . $f->suggestion);
                }
            }
        }

        $confLine = sprintf('confidence %.2f · safety %.2f', $c->confidence, $c->safety);
        $worst = min($c->confidence, $c->safety);
        $severity = Severity::forScore($worst);
        $out->writeln('  ' . match ($severity) {
            'High'   => StatusLine::success($confLine, $theme),
            'Medium' => StatusLine::warn($confLine, $theme),
            default  => StatusLine::error($confLine, $theme),
        });
    }

    /** @param list<string> $tags */
    private function renderTags(array $tags, Theme $theme, bool $decorated): string
    {
        // output-046: the tag vocabulary is analyzer-internal, but the
        // finding lists these two sinks and the boundary law is mechanical
        // — no scan-adjacent string enters writeln unchecked, decorated or
        // plain alike.
        if (!$decorated) {
            return 'patterns: ' . implode(', ', array_map(
                static fn(string $t): string => self::scrub($t),
                $tags,
            ));
        }
        $chip = Style::new()
            ->bold()
            ->padding(0, 1)
            ->foreground(Color::ansi(0))
            ->background(Color::hex('#ff5fd2'));
        $out = $theme->muted->render('patterns ');
        foreach ($tags as $tag) {
            $out .= $chip->render(self::scrub($tag)) . ' ';
        }
        return rtrim($out);
    }

    /** @param list<string> $values */
    private function summarizeObserved(array $values, int $maxLen, string $kind, bool $degraded): string
    {
        // output-046: an observed value is a raw source fragment, so it
        // carries every byte the scanned file held — control bytes included.
        // Scrub through the shared boundary before it is truncated and
        // printed; length folding alone left an OSC intro intact on stdout.
        //
        // output-S27 render projection: the data layer carries ONE canonical
        // absence row; here it becomes an honest label. On an optional hole
        // whose evidence is complete (not degraded) the row states a
        // verified absence — '(absent)'; anywhere else it is an unevidenced
        // gap — '(gap)'. The pre-1.7 printable sentinel and pre-5.4
        // impostor project to the label each of them always meant.
        $verified = $kind === 'optional_block' && !$degraded;
        $shown    = array_values(array_unique(array_map(
            static function (string $v) use ($verified): string {
                if ($v === Hole::MISSING)  return $verified ? '(absent)' : '(gap)';
                if ($v === '<absent>')     return '(absent)';
                if ($v === '<missing>')    return '(gap)';
                return self::scrub($v);
            },
            $values,
        )));
        $displayed = array_map(
            static fn(string $v) => mb_strlen($v) > 40 ? mb_substr($v, 0, 37) . '...' : $v,
            array_slice($shown, 0, 5),
        );
        $extra = count($shown) > 5 ? sprintf(' (+%d more)', count($shown) - 5) : '';
        $out = implode(', ', $displayed) . $extra;
        return mb_strlen($out) > $maxLen ? mb_substr($out, 0, $maxLen - 1) . '…' : $out;
    }

    /**
     * Boundary scrub for a scanned-derived string about to reach writeln()
     * (output-046). Delegates to the shared {@see ControlText} substitute arm
     * — this reporter does not fork a second sanitizer — so ESC/CR/LF/NUL
     * become one visible replacement char (no OSC/CSI sequence can then
     * start on whoever cats the report), while clean paths and names return
     * byte-identical.
     */
    private static function scrub(string $text): string
    {
        return ControlText::substitute($text);
    }

    private static function terminalWidth(): int
    {
        $cols = (int)getenv('COLUMNS');
        if ($cols > 20) return $cols;
        if (function_exists('shell_exec')) {
            $size = @shell_exec('tput cols 2>/dev/null');
            $size = $size === null ? 0 : (int)trim($size);
            if ($size > 20) return $size;
        }
        return 100;
    }
}
