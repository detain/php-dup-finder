<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\HoleCoverage;
use Phpdup\Refactor\PlacementAdvisor;
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

        $out->writeln('');
        $out->writeln(Banner::title('phpdup', sprintf(
            '%d files · %d blocks · %d clusters · %d duplicated lines · %d total impact',
            $report->files,
            $report->blocks,
            count($clusters),
            $report->totalDuplicatedLines(),
            array_sum(array_map(fn(Cluster $c) => $c->impact, $clusters)),
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
            return;
        }

        if ($this->verbosity === self::VERBOSITY_SUMMARY_ONLY) {
            $this->renderTrailer($report, $clusters, $theme, $out);
            return;
        }

        $shown = min($limit, count($clusters));
        $out->writeln(StatusLine::info(
            sprintf('%d cluster(s); showing top %d (sorted by impact)', count($clusters), $shown),
            $theme,
        ));

        if ($this->verbosity === self::VERBOSITY_CLUSTERS) {
            $this->renderClusterList(array_slice($clusters, 0, $shown), $theme, $out);
            $this->renderTrailer($report, $clusters, $theme, $out);
            return;
        }

        for ($i = 0; $i < $shown; $i++) {
            $this->renderCluster($i + 1, $clusters[$i], $report->config, $advisor, $theme, $width, $decorated, $out);
        }

        $this->renderTrailer($report, $clusters, $theme, $out);
    }

    /**
     * Trailing summary line — kept identical across verbosity levels so
     * scripts grepping for the totals work regardless of mode.
     *
     * @param list<Cluster> $clusters
     */
    private function renderTrailer(Report $report, array $clusters, Theme $theme, OutputInterface $out): void
    {
        $out->writeln('');
        $out->writeln(StatusLine::success(sprintf(
            'summary  %d clusters · %d duplicated lines · %d total impact',
            count($clusters),
            $report->totalDuplicatedLines(),
            array_sum(array_map(fn(Cluster $c) => $c->impact, $clusters)),
        ), $theme));
        $this->renderFileAccounting($report, $theme, $out);
    }

    /**
     * File-accounting surface (0.6). Emits nothing when the producer never
     * tracked the buckets (legacy `new Report(...)` callers pass nulls);
     * when files skipped extraction or failed to parse, a warn line names
     * them, and the per-bucket breakdown `files  N scanned · ...` gives
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
     * @param list<Cluster> $clusters
     */
    private function renderClusterList(array $clusters, Theme $theme, OutputInterface $out): void
    {
        $out->writeln('');
        $table = Table::new()
            ->border(Border::rounded())
            ->headers('CLUSTER', 'MEMBERS', 'SIM', 'IMPACT', 'KIND', 'LOCATION');
        foreach ($clusters as $c) {
            $loc = isset($c->members[0]) ? $c->members[0]->location() : '';
            $table = $table->row(
                $c->id,
                (string)$c->size(),
                sprintf('%.2f', $c->similarity),
                (string)$c->impact,
                isset($c->members[0]->kind) ? $c->members[0]->kind : '',
                $loc,
            );
        }
        $out->writeln($table->render());
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
                $m->location() . $marker,
                $m->kind,
                $m->qualifiedName(),
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
            $out->writeln($sigStyle->render($c->signature));
        }

        if ($c->holes) {
            $out->writeln('');
            $out->writeln(Section::header('Holes', $theme, leftPad: 2, width: $width));
            $holesTable = Table::new()
                ->border(Border::rounded())
                ->headers('PARAM', 'TYPE', 'KIND', 'OBSERVED');
            foreach ($c->holes as $h) {
                $holesTable = $holesTable->row(
                    $h->suggestedName,
                    $h->inferredType,
                    $h->kind,
                    $this->summarizeObserved($h->observedValues, 60),
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
        $placement = $advisor->advise($c);
        $whereLine = sprintf('placement: %s — %s', $placement['shape'], $placement['rationale']);
        if ($placement['host_packages'] !== null && $placement['host_packages'] !== []) {
            $whereLine .= ' [shared host: ' . implode(', ', $placement['host_packages']) . ']';
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
        if (!$decorated) {
            return 'patterns: ' . implode(', ', $tags);
        }
        $chip = Style::new()
            ->bold()
            ->padding(0, 1)
            ->foreground(Color::ansi(0))
            ->background(Color::hex('#ff5fd2'));
        $out = $theme->muted->render('patterns ');
        foreach ($tags as $tag) {
            $out .= $chip->render($tag) . ' ';
        }
        return rtrim($out);
    }

    /** @param list<string> $values */
    private function summarizeObserved(array $values, int $maxLen): string
    {
        $unique = array_values(array_unique($values));
        $shown  = array_map(
            static fn(string $v) => ($v === Hole::MISSING || $v === '<missing>') ? '(gap)' : $v,
            array_slice($unique, 0, 5),
        );
        $rendered = array_map(fn($v) => mb_strlen($v) > 40 ? mb_substr($v, 0, 37) . '...' : $v, $shown);
        $extra = count($unique) > 5 ? sprintf(' (+%d more)', count($unique) - 5) : '';
        $out = implode(', ', $rendered) . $extra;
        return mb_strlen($out) > $maxLen ? mb_substr($out, 0, $maxLen - 1) . '…' : $out;
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
