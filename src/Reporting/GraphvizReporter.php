<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Util\ControlText;

/**
 * Renders the cluster set to Graphviz DOT, ready for rendering with
 * `dot -Tpng phpdup.dot -o phpdup.png`.
 *
 * Layout:
 *   - One cluster node per Cluster (label: id + impact).
 *   - One file node per file participating in any cluster.
 *   - Edges: file → cluster for each membership.
 *
 * Co-occurrence between files (two files appearing in the same
 * cluster) is implicit through the shared cluster node — no need to
 * emit explicit file-to-file edges, which would clutter the graph
 * for clusters with >2 members.
 */
final class GraphvizReporter
{
    use WritesReportFile;

    public function writeTo(Report $report, string $file): void
    {
        $this->writeFile($file, $this->build($report));
    }

    public function build(Report $report): string
    {
        $out = "digraph phpdup {\n";
        $out .= "  graph [rankdir=LR, splines=true, overlap=false];\n";
        $out .= "  node  [fontname=\"Inter\", fontsize=11];\n";
        $out .= "  edge  [color=\"#777777\"];\n\n";

        $files = [];
        foreach ($report->clusters as $c) {
            foreach ($c->members as $m) {
                $files[$m->file] = true;
            }
        }

        $out .= "  // files\n";
        foreach (array_keys($files) as $f) {
            $out .= sprintf(
                "  %s [shape=box, style=\"rounded,filled\", fillcolor=\"#f3f4f6\", label=%s];\n",
                $this->id('file_' . $f),
                $this->dotString(basename($f)),
            );
        }
        $out .= "\n  // clusters\n";
        foreach ($report->clusters as $c) {
            $colour = $this->colourForImpact($c->impact);
            $out .= sprintf(
                "  %s [shape=ellipse, style=\"filled\", fillcolor=%s, label=%s];\n",
                $this->id('cluster_' . $c->id),
                $this->dotString($colour),
                // Real LF, not a hand-escaped \n: dotString is now the sole
                // place that decides how a break reaches the wire (the old
                // literal survived double-escaping and rendered as text).
                $this->dotString(sprintf("%s\nimpact %d · %d members", $c->id, $c->impact, $c->size())),
            );
        }
        $out .= "\n  // memberships\n";
        foreach ($report->clusters as $c) {
            foreach ($c->members as $m) {
                $out .= sprintf(
                    "  %s -> %s;\n",
                    $this->id('file_' . $m->file),
                    $this->id('cluster_' . $c->id),
                );
            }
        }

        $out .= "}\n";
        return $out;
    }

    private function id(string $raw): string
    {
        // Graphviz ids must be alphanumeric or quoted. Hash and prefix
        // so we never collide and stay under the unquoted-id ruleset.
        return 'n_' . substr(md5($raw), 0, 12);
    }

    /**
     * Quote a string as a single DOT label (output-047). The pre-fix escaper
     * covered only backslash and double quote, so a newline in a scanned
     * basename split the quoted label into arbitrary DOT statements — the
     * audit-integrity class (humans trust the rendered graph). This maps
     * every byte with a faithful DOT escape sequence (backslash, quote,
     * LF/CR/TAB) onto its two-char form, and substitutes the unquotable
     * residue (NUL, ESC) with the shared visible marker, so the emitted
     * label is always ONE physical line and round-trips under DOT's own
     * unescaping rules.
     */
    private function dotString(string $s): string
    {
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $s,
        );
        return '"' . strtr($escaped, ["\0" => ControlText::SUBSTITUTE, "\x1b" => ControlText::SUBSTITUTE]) . '"';
    }

    private function colourForImpact(int $impact): string
    {
        return match (Severity::forImpact($impact)) {
            'High'   => '#fda4af', // rose
            'Medium' => '#fde68a', // amber
            'Low'    => '#bef264', // lime
            default  => '#bae6fd', // sky
        };
    }
}
