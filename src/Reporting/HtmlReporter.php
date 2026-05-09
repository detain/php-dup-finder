<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;

/**
 * Static-site HTML report. One index page listing clusters by impact;
 * one detail page per cluster with member source side-by-side, holes
 * highlighted, suggested signature, pattern tags, and a sebastian/diff
 * pairwise diff.
 *
 * Index is interactive — column-sort via data-* attributes + a search
 * filter + a copy-signature clipboard button — but the JS is all
 * inlined and there's no build step. Pure PHP templates.
 */
final class HtmlReporter
{
    public function writeTo(Report $report, string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        file_put_contents($dir . '/style.css', $this->css());
        file_put_contents($dir . '/app.js', $this->js());
        file_put_contents($dir . '/index.html', $this->renderIndex($report));

        foreach ($report->clusters as $i => $cluster) {
            $name = sprintf('cluster-%03d.html', $i + 1);
            file_put_contents($dir . '/' . $name, $this->renderCluster($i + 1, $cluster, $report));
        }
    }

    private function renderIndex(Report $report): string
    {
        $minimap = $this->renderMinimap($report);
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
<?= $minimap ?>
<div class="controls">
  <input type="search" id="search" placeholder="filter clusters by signature, file, kind…" autocomplete="off">
  <span class="hint">Click a column header to sort. Hover signatures to copy.</span>
</div>
<table class="clusters" id="clusters">
<thead><tr>
  <th data-sort="num"     data-key="num">#</th>
  <th data-sort="num"     data-key="size">Members</th>
  <th data-sort="num"     data-key="similarity">Similarity</th>
  <th data-sort="num"     data-key="impact" data-default-desc="1">Impact</th>
  <th data-sort="num"     data-key="confidence">Confidence</th>
  <th data-sort="text"    data-key="patterns">Patterns</th>
  <th>Signature</th>
</tr></thead><tbody>
<?php foreach ($report->clusters as $i => $c): ?>
<tr data-search="<?= htmlspecialchars($this->searchString($c)) ?>">
  <td data-num="<?= $i + 1 ?>"><a href="<?= sprintf('cluster-%03d.html', $i + 1) ?>">#<?= $i + 1 ?></a></td>
  <td class="num" data-num="<?= $c->size() ?>"><?= $c->size() ?></td>
  <td class="num" data-num="<?= $c->similarity ?>">
    <?= number_format($c->similarity, 2) ?><?= $c->exact ? ' <span class="exact">exact</span>' : '' ?>
  </td>
  <td class="num" data-num="<?= $c->impact ?>"><?= $c->impact ?></td>
  <td class="num" data-num="<?= $c->confidence ?>"><?= number_format($c->confidence, 2) ?></td>
  <td><?= htmlspecialchars(implode(', ', $c->patternTags)) ?></td>
  <td>
    <button class="copy" data-copy="<?= htmlspecialchars((string)$c->signature) ?>" title="Copy suggested signature to clipboard">⎘</button>
    <code><?= htmlspecialchars($c->signature ? $this->firstLine($c->signature) : '') ?></code>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table>
</main>
<script src="app.js"></script>
</body></html>
        <?php
        return (string)ob_get_clean();
    }

    private function renderMinimap(Report $report): string
    {
        if ($report->clusters === []) {
            return '';
        }
        $maxImpact = 1;
        foreach ($report->clusters as $c) {
            if ($c->impact > $maxImpact) $maxImpact = $c->impact;
        }
        $bars = '';
        foreach ($report->clusters as $i => $c) {
            $h = max(2, (int)round(($c->impact / $maxImpact) * 60));
            $cls = $c->exact ? 'exact' : 'near';
            $bars .= sprintf(
                '<a class="bar %s" style="height:%dpx" href="cluster-%03d.html" title="#%d · impact %d · members %d"></a>',
                $cls, $h, $i + 1, $i + 1, $c->impact, $c->size(),
            );
        }
        return '<section class="minimap"><h2>Cluster impact distribution</h2>' .
               '<div class="bars">' . $bars . '</div>' .
               '<p class="hint">Bar height = impact. Green = exact, blue = near-duplicate. Click to jump.</p>' .
               '</section>';
    }

    private function searchString(Cluster $c): string
    {
        $bits = [$c->id, (string)$c->signature, implode(' ', $c->patternTags)];
        foreach ($c->members as $m) {
            $bits[] = $m->file;
            $bits[] = $m->qualifiedName();
            $bits[] = $m->kind;
        }
        return strtolower(implode(' ', $bits));
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
<section><h2>Suggested abstraction
  <button class="copy" data-copy="<?= htmlspecialchars($c->signature) ?>" title="Copy to clipboard">⎘ copy</button>
</h2>
<pre class="signature"><?= $this->highlightPhp($c->signature) ?></pre>
</section>
<?php endif; ?>

<?php if ($c->holes): ?>
<section><h2>Holes</h2>
<table class="holes"><thead><tr>
  <th>Placeholder</th><th>Suggested</th><th>Type</th><th>Kind</th><th>Observed</th>
</tr></thead><tbody>
<?php foreach ($c->holes as $h): ?>
<tr class="hole-<?= htmlspecialchars($h->kind) ?>">
  <td><code><?= htmlspecialchars($h->placeholder) ?></code></td>
  <td><code><?= htmlspecialchars($h->suggestedName) ?></code></td>
  <td><?= htmlspecialchars($h->inferredType) ?></td>
  <td><?= htmlspecialchars($h->kind) ?>
      <?php if ($h->kind === 'optional_block'): ?>
        <span class="badge optional">type-3</span>
      <?php endif; ?>
  </td>
  <td><?php
      $vals = array_unique($h->observedValues);
      $first = true;
      foreach ($vals as $v) {
          if (!$first) echo ', ';
          $first = false;
          if ($v === '<absent>') {
              echo '<span class="absent">&lt;absent&gt;</span>';
          } else {
              echo htmlspecialchars($v);
          }
      }
  ?></td>
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
  <pre class="src"><?= $this->highlightPhp($src) ?></pre>
</div>
<?php endforeach; ?>
</div>
</section>

<?php if (count($c->members) >= 2): ?>
<section><h2>Diff: member 1 vs member 2</h2>
<pre class="diff"><?= $this->renderDiff($sources[0] ?? '', $sources[1] ?? '') ?></pre>
</section>
<?php endif; ?>

</main>
<script src="app.js"></script>
</body></html>
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

    /**
     * Tiny PHP syntax highlighter — keywords, strings, comments, numbers.
     * No external dep; output is HTML with <span class="...">.
     */
    private function highlightPhp(string $code): string
    {
        $escaped = htmlspecialchars($code);
        // Order matters: comments first (block, then line), then strings, numbers, keywords.
        // Use ~ as delimiter so the &#039; entity below doesn't accidentally close the pattern.
        $escaped = preg_replace('~/\*.*?\*/~s', '<span class="c">$0</span>', $escaped) ?? $escaped;
        $escaped = preg_replace('~//[^\n]*~', '<span class="c">$0</span>', $escaped) ?? $escaped;
        $escaped = preg_replace('~&\#039;[^&]*?&\#039;~', '<span class="s">$0</span>', $escaped) ?? $escaped;
        $escaped = preg_replace('~&quot;[^&]*?&quot;~', '<span class="s">$0</span>', $escaped) ?? $escaped;
        $kw = 'function|return|if|else|elseif|foreach|for|while|do|switch|case|break|continue|new|class|interface|trait|extends|implements|public|private|protected|static|readonly|final|abstract|use|namespace|try|catch|finally|throw|null|true|false|array|int|string|float|bool|mixed|self|parent|this|void|never|use|match|enum';
        $escaped = preg_replace('~\b(' . $kw . ')\b~', '<span class="k">$1</span>', $escaped) ?? $escaped;
        $escaped = preg_replace('~\b(\d+(?:\.\d+)?)\b~', '<span class="n">$1</span>', $escaped) ?? $escaped;
        return $escaped;
    }

    private function firstLine(string $s): string
    {
        $i = strpos($s, "\n");
        return $i === false ? $s : substr($s, 0, $i);
    }

    private function js(): string
    {
        return <<<'JS'
(() => {
  // Sort table by clicking <th data-sort="num|text" data-key="…">.
  const table = document.getElementById('clusters');
  if (table) {
    let lastKey = null, asc = true;
    table.querySelectorAll('th[data-sort]').forEach((th, idx) => {
      th.style.cursor = 'pointer';
      th.addEventListener('click', () => {
        const sort = th.dataset.sort;
        const key  = th.dataset.key;
        const tbody = table.tBodies[0];
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const defaultDesc = th.dataset.defaultDesc === '1';
        if (lastKey === key) {
          asc = !asc;
        } else {
          asc = !defaultDesc;
        }
        lastKey = key;
        const cellValue = (row) => {
          const cell = row.cells[idx];
          if (sort === 'num') return parseFloat(cell.dataset.num ?? cell.textContent);
          return cell.textContent.trim().toLowerCase();
        };
        rows.sort((a, b) => {
          const av = cellValue(a), bv = cellValue(b);
          if (av < bv) return asc ? -1 : 1;
          if (av > bv) return asc ? 1 : -1;
          return 0;
        });
        rows.forEach(r => tbody.appendChild(r));
        table.querySelectorAll('th[data-sort]').forEach(o => o.classList.remove('sorted-asc', 'sorted-desc'));
        th.classList.add(asc ? 'sorted-asc' : 'sorted-desc');
      });
    });
  }

  // Live filter rows by signature/file/kind via [data-search].
  const search = document.getElementById('search');
  if (search) {
    search.addEventListener('input', () => {
      const q = search.value.toLowerCase().trim();
      document.querySelectorAll('#clusters tbody tr').forEach(tr => {
        tr.style.display = !q || tr.dataset.search.includes(q) ? '' : 'none';
      });
    });
  }

  // Copy-to-clipboard buttons.
  document.querySelectorAll('button.copy').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      try {
        await navigator.clipboard.writeText(btn.dataset.copy || '');
        const orig = btn.textContent;
        btn.textContent = '✓';
        setTimeout(() => { btn.textContent = orig; }, 1200);
      } catch {
        // Best-effort fallback for non-secure contexts.
        const ta = document.createElement('textarea');
        ta.value = btn.dataset.copy || '';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }
    });
  });
})();
JS;
    }

    private function css(): string
    {
        return <<<CSS
:root {
  --fg: #1f2933; --bg: #fafbfc; --muted: #65737e; --accent: #0366d6;
  --border: #e1e4e8; --add: #e6ffed; --del: #ffeef0; --hunk: #f1f8ff;
  --kw: #d73a49; --str: #032f62; --comment: #6a737d; --num: #005cc5;
  --bar-exact: #2ea44f; --bar-near: #0366d6;
}
* { box-sizing: border-box; }
body { font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
       color: var(--fg); background: var(--bg); margin: 0; padding: 0; }
header { padding: 24px 32px; border-bottom: 1px solid var(--border); background: white; }
header h1 { margin: 0 0 12px; font-size: 22px; }
main { max-width: 1280px; margin: 0 auto; padding: 24px 32px; }
section { margin-bottom: 32px; }
h2 { font-size: 18px; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
h3 { font-size: 14px; margin: 12px 0 4px; color: var(--accent); font-family: ui-monospace, monospace; }
.summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin: 0; }
.summary dt { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); }
.summary dd { margin: 0 0 8px; font-weight: 600; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 6px 10px; text-align: left; border-bottom: 1px solid var(--border); }
th { background: white; font-weight: 600; color: var(--muted); user-select: none; }
th[data-sort]:hover { color: var(--accent); }
th.sorted-asc::after  { content: ' ▲'; font-size: 10px; }
th.sorted-desc::after { content: ' ▼'; font-size: 10px; }
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
.hole-optional_block { background: #fffbeb; }
.hole-optional_block td { border-bottom-color: #fde68a; }
.absent { color: #d97706; font-style: italic; }
.badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 11px; margin-left: 6px; }
.badge.optional { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

/* Controls bar */
.controls { display: flex; gap: 16px; align-items: center; margin: 12px 0; }
.controls input[type=search] {
  padding: 6px 10px; font-size: 13px; border: 1px solid var(--border);
  border-radius: 4px; flex: 0 0 360px; background: white;
}
.controls .hint { color: var(--muted); font-size: 12px; }

/* Minimap (cluster impact distribution) */
.minimap .bars { display: flex; align-items: flex-end; gap: 2px; height: 64px;
                 padding: 8px; background: white; border: 1px solid var(--border); border-radius: 6px; }
.minimap .bar { flex: 1; min-width: 4px; background: var(--bar-near); border-radius: 2px 2px 0 0;
                transition: filter 0.15s; }
.minimap .bar:hover { filter: brightness(1.2); outline: 1px solid var(--accent); }
.minimap .bar.exact { background: var(--bar-exact); }
.minimap .hint { color: var(--muted); font-size: 12px; margin: 6px 0 0; }

/* Copy buttons */
button.copy {
  border: 1px solid var(--border); background: white; color: var(--muted);
  font: inherit; cursor: pointer; padding: 2px 8px; border-radius: 4px;
}
button.copy:hover { color: var(--accent); border-color: var(--accent); }

/* Inline syntax highlighting */
.signature .k, .src .k { color: var(--kw); font-weight: 600; }
.signature .s, .src .s { color: var(--str); }
.signature .c, .src .c { color: var(--comment); font-style: italic; }
.signature .n, .src .n { color: var(--num); }
CSS;
    }
}
