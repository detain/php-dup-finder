<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;

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
    public function writeTo(Report $report, string $file): void
    {
        $dir = dirname($file);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        file_put_contents($file, $this->build($report));
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
                $this->dotString(sprintf("%s\\nimpact %d · %d members", $c->id, $c->impact, $c->size())),
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

    private function dotString(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    private function colourForImpact(int $impact): string
    {
        return match (true) {
            $impact >= 200 => '#fda4af', // rose
            $impact >= 100 => '#fde68a', // amber
            $impact >= 40  => '#bef264', // lime
            default        => '#bae6fd', // sky
        };
    }
}
