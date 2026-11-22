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
 * highlighted, suggested signature, pattern tags, and a sebastian/diff
 * pairwise diff.
 *
 * Index is interactive — column-sort via data-* attributes + a search
 * filter + a copy-signature clipboard button — but the JS is all
 * inlined and there's no build step. Pure PHP templates.
 *
 * output-038: member source arrives through the shared per-run
 * {@see SourceTextStore} — one read per file per run, symlink-confinement
 * refusals included. A member whose source could not be read renders an
 * explicit `[source not read: reason]` marker, never an empty cell that
 * reads like a real (empty) source, and the pairwise diff section states
 * the same posture instead of diffing placeholder bytes.
 *
 * output-041: two throughput arms, rendering unchanged.
 * - Cluster pages are batched into name => html groups flushed together
 *   ({@see DEFAULT_WRITE_BATCH}); batch size 1 reproduces the old
 *   per-page streaming writer byte for byte.
 * - One {@see Differ} serves every diff section of a run instead of a
 *   fresh instance per cluster page ({@see differBuilds()} is the seam).
 *
 * output-053: the highlighter is lexer-driven — {@see highlightPhp()}
 * classifies raw-fragment tokens from PHP's own tokenizer (strings and
 * comments are atomic by the engine's definition, every comment style is
 * recognised, the CSS comment class .c is actually emitted) and keywords
 * come from one shared list, {@see KEYWORDS}. The index table's sort JS
 * reads the column cell by the header's absolute position, a contract the
 * markup carries and HtmlReporterTest pins (see the thead comment in
 * {@see renderIndex()}).
 */
final class HtmlReporter
{
    /** Cluster pages rendered into one write batch before it is flushed. */
    public const DEFAULT_WRITE_BATCH = 128;

    /**
     * The single source of truth for highlighted keywords — lower-case
     * spellings, matched case-insensitively by {@see highlightPhp()}.
     * output-053: the old regex alternation carried the namespace/trait
     * import entry twice (main list and trailing arm); this list is
     * de-duplicated and its lack of repeats is pinned.
     *
     * @var list<string>
     */
    private const KEYWORDS = [
        'function', 'return', 'if', 'elseif', 'foreach', 'for', 'while', 'do',
        'switch', 'case', 'break', 'continue', 'new', 'class', 'interface',
        'trait', 'extends', 'implements', 'public', 'private', 'protected',
        'static', 'readonly', 'final', 'abstract', 'use', 'namespace', 'try',
        'catch', 'finally', 'throw', 'null', 'true', 'false', 'array', 'int',
        'string', 'float', 'bool', 'mixed', 'self', 'parent', 'void', 'never',
        'match', 'enum',
    ];

    private ?Differ $differ = null;

    private int $differBuilds = 0;

    private int $pageWriteFlushes = 0;

    /**
     * @param int $writeBatchSize cluster pages held in the render batch
     *                            before a flush; 1 is the streaming writer
     *                            (each page written as it renders).
     */
    public function __construct(
        private readonly ?SourceTextStore $sourceText = null,
        private readonly int $writeBatchSize = self::DEFAULT_WRITE_BATCH,
    ) {
        if ($writeBatchSize < 1) {
            throw new \InvalidArgumentException("writeBatchSize must be >= 1, got {$writeBatchSize}");
        }
    }

    public function writeTo(Report $report, string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $store = $this->sourceText ?? new SourceTextStore($report->config->paths);

        // output-041: the Differ memo is run-scoped — a fresh run builds its
        // own instance (the construction counter below stays cumulative).
        $this->differ = null;

        file_put_contents($dir . '/style.css', $this->css());
        file_put_contents($dir . '/app.js', $this->js());
        file_put_contents($dir . '/index.html', $this->renderIndex($report));

        // output-041: batched page writes — pages render into the batch and
        // flush as a group, so a large report no longer interleaves one
        // write per render.
        $batch = [];
        foreach ($report->clusters as $i => $cluster) {
            $batch[sprintf('cluster-%03d.html', $i + 1)] = $this->renderCluster($i + 1, $cluster, $report, $store);
            if (count($batch) >= $this->writeBatchSize) {
                $this->flushPages($dir, $batch);
                $batch = [];
            }
        }
        $this->flushPages($dir, $batch);
    }

    /**
     * Differ instances built so far (cumulative across runs). A run with
     * several diff pages must build exactly one — output-041's seam, in the
     * spirit of RefactorPatchReporter's public counter.
     */
    public function differBuilds(): int
    {
        return $this->differBuilds;
    }

    /**
     * Non-empty page batches flushed so far (cumulative across runs) —
     * the observable shape of the batching mode: N pages at batch 1 flush
     * N times, at batch N flush once.
     */
    public function pageWriteFlushes(): int
    {
        return $this->pageWriteFlushes;
    }

    /**
     * @param array<string, string> $pages file name => rendered html
     */
    private function flushPages(string $dir, array $pages): void
    {
        if ($pages === []) {
            return;
        }
        $this->pageWriteFlushes++;
        foreach ($pages as $name => $html) {
            file_put_contents($dir . '/' . $name, $html);
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
<!-- Column-index contract (output-053): a header cell's absolute position
     among the thead cells is the body-row cell index app.js sorts by; keep
     header and body cells 1:1 (no colspans) and every data-key aligned with
     the column it names — HtmlReporterTest pins both sides of this. -->
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

    private function renderCluster(int $num, Cluster $c, Report $report, SourceTextStore $store): string
    {
        $sources = $this->loadSources($c, $store);
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
<p class="hole-help">
  Edit <em>Suggested</em> / <em>Type</em> below to tweak the abstraction.
  Changes persist in <code>localStorage</code> and don't affect the
  source file. Click <em>Copy as JSON</em> to export.
</p>
<table class="holes" data-cluster-id="<?= htmlspecialchars($c->id) ?>"><thead><tr>
  <th>Placeholder</th><th>Suggested</th><th>Type</th><th>Kind</th><th>Observed</th>
</tr></thead><tbody>
<?php foreach ($c->holes as $h): ?>
<tr class="hole-<?= htmlspecialchars($h->kind) ?>" data-hole="<?= htmlspecialchars($h->placeholder) ?>">
  <td><code><?= htmlspecialchars($h->placeholder) ?></code></td>
  <td><input class="hole-name"
             data-default="<?= htmlspecialchars($h->suggestedName) ?>"
             value="<?= htmlspecialchars($h->suggestedName) ?>"></td>
  <td><input class="hole-type"
             data-default="<?= htmlspecialchars($h->inferredType) ?>"
             value="<?= htmlspecialchars($h->inferredType) ?>"></td>
  <td><?= htmlspecialchars($h->kind) ?>
      <?php if ($h->kind === 'optional_block'): ?>
        <span class="badge optional">type-3</span>
      <?php endif; ?>
  </td>
  <td><?php
      $vals = array_unique($h->observedValues);
      // output-S27 render projection: one canonical absence row in the
      // data; the honest label is chosen here. Optional hole with
      // complete evidence → its MISSING rows are verified absences
      // (.absent); anywhere else a MISSING row is an unevidenced gap
      // (.gap). The two printable forms below are display-only.
      $verified = $h->kind === 'optional_block' && !$h->degraded;
      $first = true;
      foreach ($vals as $v) {
          if (!$first) echo ', ';
          $first = false;
          if ($v === '<absent>' || ($v === Hole::MISSING && $verified)) {
              echo '<span class="absent">&lt;absent&gt;</span>';
          } elseif ($v === Hole::MISSING || $v === '<missing>') {
              // M1 parity: a degraded hole carries '' in memory — render the
              // same honest marker JSON (null+degraded) and CLI ((gap)) show,
              // never an empty cell that reads like a real empty value.
              echo '<span class="gap">(gap)</span>';
          } else {
              echo htmlspecialchars($v);
          }
      }
  ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<p class="hole-actions">
  <button class="copy-tweaks" type="button">Copy tweaks as JSON</button>
  <button class="reset-tweaks" type="button">Reset to defaults</button>
  <span class="copy-status" hidden>copied!</span>
</p>
</section>
<?php endif; ?>

<section><h2>Members</h2>
<div class="members">
<?php foreach ($c->members as $idx => $m): $src = $sources[$idx] ?? null; ?>
<div class="member">
  <h3><?= htmlspecialchars($m->location()) ?></h3>
  <p class="meta"><?= htmlspecialchars($m->qualifiedName()) ?> · <?= htmlspecialchars($m->kind) ?> · <?= $m->size ?> nodes</p>
  <pre class="src"><?= $src === null ? $this->unfetched($store, $m->file) : $this->highlightPhp($src) ?></pre>
</div>
<?php endforeach; ?>
</div>
</section>

<?php if (count($c->members) >= 2): ?>
<?php $d0 = $sources[0] ?? null; $d1 = $sources[1] ?? null; ?>
<section><h2>Diff: member 1 vs member 2</h2>
<?php if ($d0 !== null && $d1 !== null): ?>
<pre class="diff"><?= $this->renderDiff($d0, $d1) ?></pre>
<?php else: ?>
<pre class="diff"><?= $this->diffSkipped($store, $c, $d0, $d1) ?></pre>
<?php endif; ?>
</section>
<?php endif; ?>

</main>
<script src="app.js"></script>
</body></html>
        <?php
        return (string)ob_get_clean();
    }

    /**
     * @return list<string|null> one entry per member; null = the store
     *                           refused the read (explicit unfetched posture,
     *                           never an empty string masquerading as source)
     */
    private function loadSources(Cluster $c, SourceTextStore $store): array
    {
        $out = [];
        foreach ($c->members as $m) {
            $lines = $store->lines($m->file);
            if ($lines === null) {
                $out[] = null;
                continue;
            }
            $slice = array_slice($lines, $m->range->start - 1, $m->range->lines());
            $out[] = implode("\n", $slice);
        }
        return $out;
    }

    /**
     * The explicit unfetched marker for one member whose source the store
     * refused. The reason string is store-controlled vocabulary; the path
     * itself is already rendered by the member header above, so no scanned
     * text rides this line unescaped (the label passes htmlspecialchars like
     * every other bit of text in the page).
     */
    private function unfetched(SourceTextStore $store, string $file): string
    {
        return '<span class="unfetched">'
             . htmlspecialchars('[' . SourceTextStore::UNFETCHED . ': ' . ($store->reason($file) ?? 'unavailable') . ']', ENT_QUOTES, 'UTF-8')
             . '</span>';
    }

    /**
     * The diff section's honest statement when either side was never
     * fetched: name which member(s) could not be read and why, instead of
     * rendering a diff of placeholder bytes that would read as real data.
     */
    private function diffSkipped(SourceTextStore $store, Cluster $c, ?string $d0, ?string $d1): string
    {
        $sides = [];
        if ($d0 === null) {
            $sides[] = 'member 1: ' . ($store->reason($c->members[0]->file) ?? 'unavailable');
        }
        if ($d1 === null) {
            $sides[] = 'member 2: ' . ($store->reason($c->members[1]->file) ?? 'unavailable');
        }
        return '<span class="unfetched">' . htmlspecialchars(
            '[diff skipped: ' . SourceTextStore::UNFETCHED . ' (' . implode('; ', $sides) . ')]',
            ENT_QUOTES, 'UTF-8',
        ) . '</span>';
    }

    private function renderDiff(string $a, string $b): string
    {
        // output-041: one Differ per run (built lazily on the first diff
        // page); construction was per cluster page before. Differ::diff is
        // stateless, so the memoized instance renders identically.
        if ($this->differ === null) {
            $this->differ = new Differ(new StrictUnifiedDiffOutputBuilder([
                'fromFile' => 'member 1', 'toFile' => 'member 2',
            ]));
            $this->differBuilds++;
        }
        $diff = $this->differ->diff($a, $b);
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
     * PHP syntax highlighter driven by the engine's own lexer: the raw
     * fragment goes through {@see token_get_all()} and every token is
     * escaped (ENT_QUOTES, UTF-8) and wrapped as one unit. Strings,
     * comments (all styles: `//`, `#`, `/* *\/`, docblocks) and numbers are
     * atomic by PHP's definition of them — output-053's core defect was the
     * old escape-then-regex design, whose single-quote arm could not span a
     * newline (legal inside a PHP single-quoted string) or an `&`, leaking
     * the string's words into the keyword/number arms.
     *
     * Classes emitted: .s string (literal or heredoc segment), .c comment,
     * .n number (ints, floats, hex, `1_000` separators), .k keyword — a
     * bare word in {@see KEYWORDS}, compared case-insensitively like PHP
     * itself matches keywords. A keyword directly followed by `=` is left
     * plain: HTML-ish fragments such as class="c" can reach the highlighter
     * as scanned text, and wrapping the word mid-attribute corrupts the
     * rendered markup (the legacy guard, kept as {@see followedByAssign()};
     * real `=>` lexes as T_DOUBLE_ARROW and is unaffected). Everything else
     * — whitespace, variables, punctuation, inline HTML between `?>` and
     * `<?php` — is escaped plain.
     *
     * The fragment is lexed behind a synthetic open tag (dropped before
     * rendering) because member-source slices and signature lines are PHP
     * *bodies*; the lexer only enters code mode at an open tag. Escape
     * posture is unchanged from the pre-lexer design: htmlspecialchars per
     * unit with the same flags, so a hostile token still cannot open or
     * close markup (once-S60 owns the non-UTF-8 byte hygiene upgrade).
     */
    private function highlightPhp(string $code): string
    {
        $tokens = token_get_all("<?php\n" . $code);
        // Drop the synthetic opener (its text is not part of $code).
        array_shift($tokens);

        $out = '';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $out .= self::htmlSpan('c', $text);
                continue;
            }
            if (
                $id === T_CONSTANT_ENCAPSED_STRING
                || $id === T_ENCAPSED_AND_WHITESPACE
                || $id === T_START_HEREDOC
                || $id === T_END_HEREDOC
            ) {
                $out .= self::htmlSpan('s', $text);
                continue;
            }
            if ($id === T_LNUMBER || $id === T_DNUMBER) {
                $out .= self::htmlSpan('n', $text);
                continue;
            }
            if (
                preg_match('/^[A-Za-z_]+$/', $text) === 1
                && in_array(strtolower($text), self::KEYWORDS, true)
                && !$this->followedByAssign($tokens, $i)
            ) {
                $out .= self::htmlSpan('k', $text);
                continue;
            }
            $out .= htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
        return $out;
    }

    /**
     * True when the next non-whitespace token is a plain `=` — the
     * HTML-attribute guard described in {@see highlightPhp()}.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function followedByAssign(array $tokens, int $index): bool
    {
        for ($j = $index + 1, $n = count($tokens); $j < $n; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            return is_string($tokens[$j]) && $tokens[$j] === '=';
        }
        return false;
    }

    private static function htmlSpan(string $class, string $text): string
    {
        return '<span class="' . $class . '">'
             . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
             . '</span>';
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
  //
  // Column-index contract (output-053): the cell a sort reads is the
  // clicked header's ABSOLUTE position among its header row's children —
  // NOT its rank among sortable headers (that was correct only while every
  // non-sortable column stayed last). The position must equal the index of
  // the same column's <td> in every body row: header and body cells stay
  // 1:1 (no colspans) and each data-key keeps the column it names. The
  // generated markup and this derivation are pinned by HtmlReporterTest's
  // column-contract tests — move or insert a column and they fail first.
  const table = document.getElementById('clusters');
  if (table) {
    let lastKey = null, asc = true;
    table.querySelectorAll('th[data-sort]').forEach((th) => {
      th.style.cursor = 'pointer';
      th.addEventListener('click', () => {
        const sort = th.dataset.sort;
        const key  = th.dataset.key;
        const cellIdx = Array.prototype.indexOf.call(th.parentNode.children, th);
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
          const cell = row.cells[cellIdx];
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

  // Hole-tweaker UI. Per-cluster: persist edits to localStorage,
  // apply on load, expose copy-as-JSON and reset buttons.
  document.querySelectorAll('table.holes[data-cluster-id]').forEach(table => {
    const clusterId = table.dataset.clusterId;
    const storeKey = 'phpdup-tweaks-' + clusterId;
    const restore = () => {
      try {
        const raw = localStorage.getItem(storeKey);
        if (!raw) return;
        const data = JSON.parse(raw);
        Object.entries(data).forEach(([placeholder, fields]) => {
          const row = table.querySelector('tr[data-hole="' + placeholder + '"]');
          if (!row) return;
          if (fields.suggestedName !== undefined) {
            row.querySelector('input.hole-name').value = fields.suggestedName;
          }
          if (fields.inferredType !== undefined) {
            row.querySelector('input.hole-type').value = fields.inferredType;
          }
        });
      } catch (e) { /* ignore corrupt cache */ }
    };
    const persist = () => {
      const data = {};
      table.querySelectorAll('tbody tr[data-hole]').forEach(row => {
        const placeholder = row.dataset.hole;
        const nameInput = row.querySelector('input.hole-name');
        const typeInput = row.querySelector('input.hole-type');
        const fields = {};
        if (nameInput.value !== nameInput.dataset.default) fields.suggestedName = nameInput.value;
        if (typeInput.value !== typeInput.dataset.default) fields.inferredType  = typeInput.value;
        if (Object.keys(fields).length) data[placeholder] = fields;
      });
      try { localStorage.setItem(storeKey, JSON.stringify(data)); } catch (e) { /* quota */ }
    };

    restore();
    table.querySelectorAll('input').forEach(i => i.addEventListener('input', persist));

    const section = table.parentElement;
    const copyBtn  = section.querySelector('.copy-tweaks');
    const resetBtn = section.querySelector('.reset-tweaks');
    const status   = section.querySelector('.copy-status');
    if (copyBtn) copyBtn.addEventListener('click', async () => {
      const data = {};
      table.querySelectorAll('tbody tr[data-hole]').forEach(row => {
        const placeholder = row.dataset.hole;
        data[placeholder] = {
          suggestedName: row.querySelector('input.hole-name').value,
          inferredType:  row.querySelector('input.hole-type').value,
        };
      });
      const json = JSON.stringify({ cluster_id: clusterId, holes: data }, null, 2);
      try { await navigator.clipboard.writeText(json); } catch (e) { /* ignore */ }
      if (status) {
        status.hidden = false;
        setTimeout(() => { status.hidden = true; }, 1200);
      }
    });
    if (resetBtn) resetBtn.addEventListener('click', () => {
      table.querySelectorAll('input').forEach(i => { i.value = i.dataset.default; });
      try { localStorage.removeItem(storeKey); } catch (e) { /* ignore */ }
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
.holes input { font-family: monospace; padding: 2px 6px; border: 1px solid var(--border); border-radius: 4px; min-width: 8rem; }
.hole-help { color: var(--muted); font-size: 0.92em; margin-top: 0.5rem; }
.hole-actions { margin-top: 0.6rem; }
.hole-actions button { padding: 4px 10px; border: 1px solid var(--border); border-radius: 4px; background: white; cursor: pointer; }
.hole-actions button:hover { background: var(--bg); }
.copy-status { color: var(--accent); margin-left: 0.6rem; font-size: 0.9em; }
.hole-optional_block { background: #fffbeb; }
.hole-optional_block td { border-bottom-color: #fde68a; }
.absent { color: #d97706; font-style: italic; }
.unfetched { color: var(--muted); font-style: italic; }
.gap { color: #9ca3af; font-style: italic; }
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
