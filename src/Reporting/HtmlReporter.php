<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\Hole;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;

/**
 * Static-site HTML report. One index page listing clusters by impact;
 * one detail page per cluster with member source side-by-side, holes
 * highlighted, suggested signature, and pattern tags.
 *
 * Pure PHP templates — no JS framework, no build step.
 */
final class HtmlReporter
{
    public function writeTo(Report $report, string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        file_put_contents($dir . '/style.css', $this->css());
        file_put_contents($dir . '/index.html', $this->renderIndex($report));

        foreach ($report->clusters as $i => $cluster) {
            $name = sprintf('cluster-%03d.html', $i + 1);
            file_put_contents($dir . '/' . $name, $this->renderCluster($i + 1, $cluster, $report));
        }
    }

    private function renderIndex(Report $report): string
    {
        ob_start();
        ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><title>phpdup report</title>
<link rel="stylesheet" href="style.css">
</head><body>
<header><h1>phpdup duplicate-logic report</h1>
<dl class="summary">
  <dt>Files</dt><dd><?= $report->files ?></dd>
  <dt>Blocks</dt><dd><?= $report->blocks ?></dd>
  <dt>Parse errors</dt><dd><?= $report->parseErrors ?></dd>
  <dt>Clusters</dt><dd><?= count($report->clusters) ?></dd>
  <dt>Duplicated lines</dt><dd><?= $report->totalDuplicatedLines() ?></dd>
  <dt>Mode</dt><dd><?= htmlspecialchars($report->config->normalizationMode) ?></dd>
</dl>
</header>
<main>
<table class="clusters">
<thead><tr>
  <th>#</th><th>Members</th><th>Similarity</th><th>Impact</th><th>Confidence</th><th>Patterns</th><th>Signature</th>
</tr></thead><tbody>
<?php foreach ($report->clusters as $i => $c): ?>
<tr>
  <td><a href="<?= sprintf('cluster-%03d.html', $i + 1) ?>">#<?= $i + 1 ?></a></td>
  <td><?= $c->size() ?></td>
  <td class="num"><?= number_format($c->similarity, 2) ?><?= $c->exact ? ' <span class="exact">exact</span>' : '' ?></td>
  <td class="num"><?= $c->impact ?></td>
  <td class="num"><?= number_format($c->confidence, 2) ?></td>
  <td><?= htmlspecialchars(implode(', ', $c->patternTags)) ?></td>
  <td><code><?= htmlspecialchars($c->signature ? $this->firstLine($c->signature) : '') ?></code></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</main></body></html>
        <?php
        return (string)ob_get_clean();
    }

    private function renderCluster(int $num, Cluster $c, Report $report): string
    {
        $sources = $this->loadSources($c);
        ob_start();
        ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><title>phpdup cluster #<?= $num ?></title>
<link rel="stylesheet" href="style.css">
</head><body>
<header>
  <h1>Cluster #<?= $num ?></h1>
  <p><a href="index.html">&larr; back to index</a></p>
  <dl class="summary">
    <dt>Members</dt><dd><?= $c->size() ?></dd>
    <dt>Similarity</dt><dd><?= number_format($c->similarity, 2) ?><?= $c->exact ? ' (exact)' : '' ?></dd>
    <dt>Impact</dt><dd><?= $c->impact ?></dd>
    <dt>Confidence</dt><dd><?= number_format($c->confidence, 2) ?></dd>
    <?php if ($c->patternTags): ?>
    <dt>Patterns</dt><dd><?= htmlspecialchars(implode(', ', $c->patternTags)) ?></dd>
    <?php endif; ?>
  </dl>
</header>
<main>

<?php if ($c->signature): ?>
<section><h2>Suggested abstraction</h2>
<pre class="signature"><?= htmlspecialchars($c->signature) ?></pre>
</section>
<?php endif; ?>

<?php if ($c->holes): ?>
<section><h2>Holes</h2>
<table class="holes"><thead><tr>
  <th>Placeholder</th><th>Suggested</th><th>Type</th><th>Kind</th><th>Observed</th>
</tr></thead><tbody>
<?php foreach ($c->holes as $h): ?>
<tr>
  <td><code><?= htmlspecialchars($h->placeholder) ?></code></td>
  <td><code><?= htmlspecialchars($h->suggestedName) ?></code></td>
  <td><?= htmlspecialchars($h->inferredType) ?></td>
  <td><?= htmlspecialchars($h->kind) ?></td>
  <td><?= htmlspecialchars(implode(', ', array_unique($h->observedValues))) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</section>
<?php endif; ?>

<section><h2>Members</h2>
<div class="members">
<?php foreach ($c->members as $idx => $m): $src = $sources[$idx] ?? ''; ?>
<div class="member">
  <h3><?= htmlspecialchars($m->location()) ?></h3>
  <p class="meta"><?= htmlspecialchars($m->qualifiedName()) ?> · <?= htmlspecialchars($m->kind) ?> · <?= $m->size ?> nodes</p>
  <pre class="src"><?= htmlspecialchars($src) ?></pre>
</div>
<?php endforeach; ?>
</div>
</section>

<?php if (count($c->members) >= 2): ?>
<section><h2>Diff: member 1 vs member 2</h2>
<pre class="diff"><?= $this->renderDiff($sources[0] ?? '', $sources[1] ?? '') ?></pre>
</section>
<?php endif; ?>

</main></body></html>
        <?php
        return (string)ob_get_clean();
    }

    /** @return list<string> */
    private function loadSources(Cluster $c): array
    {
        $out = [];
        foreach ($c->members as $m) {
            $contents = @file_get_contents($m->file);
            if ($contents === false) { $out[] = ''; continue; }
            $lines = preg_split("/\r?\n/", $contents) ?: [];
            $slice = array_slice($lines, $m->range->start - 1, $m->range->lines());
            $out[] = implode("\n", $slice);
        }
        return $out;
    }

    private function renderDiff(string $a, string $b): string
    {
        $diff = (new Differ(new StrictUnifiedDiffOutputBuilder([
            'fromFile' => 'member 1', 'toFile' => 'member 2',
        ])))->diff($a, $b);
        // colorize by line prefix
        $out = '';
        foreach (preg_split("/\r?\n/", $diff) ?: [] as $line) {
            $cls = '';
            if (str_starts_with($line, '+')) $cls = 'add';
            elseif (str_starts_with($line, '-')) $cls = 'del';
            elseif (str_starts_with($line, '@@')) $cls = 'hunk';
            $out .= '<span class="' . $cls . '">' . htmlspecialchars($line) . "</span>\n";
        }
        return $out;
    }

    private function firstLine(string $s): string
    {
        $i = strpos($s, "\n");
        return $i === false ? $s : substr($s, 0, $i);
    }

    private function css(): string
    {
        return <<<CSS
:root {
  --fg: #1f2933; --bg: #fafbfc; --muted: #65737e; --accent: #0366d6;
  --border: #e1e4e8; --add: #e6ffed; --del: #ffeef0; --hunk: #f1f8ff;
}
* { box-sizing: border-box; }
body { font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
       color: var(--fg); background: var(--bg); margin: 0; padding: 0; }
header { padding: 24px 32px; border-bottom: 1px solid var(--border); background: white; }
header h1 { margin: 0 0 12px; font-size: 22px; }
main { max-width: 1280px; margin: 0 auto; padding: 24px 32px; }
section { margin-bottom: 32px; }
h2 { font-size: 18px; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px solid var(--border); }
h3 { font-size: 14px; margin: 12px 0 4px; color: var(--accent); font-family: ui-monospace, monospace; }
.summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin: 0; }
.summary dt { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); }
.summary dd { margin: 0 0 8px; font-weight: 600; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 6px 10px; text-align: left; border-bottom: 1px solid var(--border); }
th { background: white; font-weight: 600; color: var(--muted); }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.exact { background: #d4edda; color: #155724; padding: 1px 6px; border-radius: 3px; font-size: 11px; }
.signature, .src, .diff { background: white; border: 1px solid var(--border); border-radius: 6px;
                          padding: 12px; overflow: auto; font: 12px/1.5 ui-monospace, monospace; }
.members { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 16px; }
.member .meta { color: var(--muted); font-size: 12px; margin: 0 0 6px; }
.diff .add { background: var(--add); display: block; }
.diff .del { background: var(--del); display: block; }
.diff .hunk { background: var(--hunk); color: var(--muted); display: block; }
.holes code { background: white; border: 1px solid var(--border); padding: 1px 4px; border-radius: 3px; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
CSS;
    }
}
