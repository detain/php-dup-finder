<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\Hole;
use SugarCraft\Core\Util\Color;
use SugarCraft\Kit\Banner;
use SugarCraft\Kit\Section;
use SugarCraft\Kit\StatusLine;
use SugarCraft\Kit\Theme;
use SugarCraft\Sprinkles\Align;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\Table\Table;
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
    public function render(Report $report, OutputInterface $out, int $limit = 50): void
    {
        $decorated = $out->isDecorated();
        $theme = $decorated ? Theme::ansi() : Theme::plain();
        $width = self::terminalWidth();
        $clusters = $report->clusters;

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
            $out->writeln(StatusLine::success('no clusters above threshold — clean.', $theme));
            return;
        }

        $shown = min($limit, count($clusters));
        $out->writeln(StatusLine::info(
            sprintf('%d cluster(s); showing top %d (sorted by impact)', count($clusters), $shown),
            $theme,
        ));

        for ($i = 0; $i < $shown; $i++) {
            $this->renderCluster($i + 1, $clusters[$i], $theme, $width, $decorated, $out);
        }

        $out->writeln('');
        $out->writeln(StatusLine::success(sprintf(
            'summary  %d clusters · %d duplicated lines · %d total impact',
            count($clusters),
            $report->totalDuplicatedLines(),
            array_sum(array_map(fn(Cluster $c) => $c->impact, $clusters)),
        ), $theme));
    }

    private function renderCluster(int $num, Cluster $c, Theme $theme, int $width, bool $decorated, OutputInterface $out): void
    {
        $out->writeln('');
        $headLabel = sprintf(
            'Cluster #%d   similarity %.2f   impact %d   members %d%s',
            $num, $c->similarity, $c->impact, $c->size(),
            $c->exact ? '   EXACT' : ''
        );
        $out->writeln(Section::header($headLabel, $theme, leftPad: 2, width: $width));

        // Members table
        $memberTable = Table::new()->border(Border::rounded())->headers('LOCATION', 'KIND', 'QUALIFIED NAME');
        foreach ($c->members as $m) {
            $memberTable = $memberTable->row($m->location(), $m->kind, $m->qualifiedName());
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
        }

        if ($c->patternTags) {
            $out->writeln('');
            $out->writeln('  ' . $this->renderTags($c->patternTags, $theme, $decorated));
        }

        $confLine = sprintf('confidence %.2f', $c->confidence);
        $out->writeln('  ' . match (true) {
            $c->confidence >= 0.85 => StatusLine::success($confLine, $theme),
            $c->confidence >= 0.65 => StatusLine::warn($confLine, $theme),
            default                => StatusLine::error($confLine, $theme),
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
        $shown = array_slice($unique, 0, 5);
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
