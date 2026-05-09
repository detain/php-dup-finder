<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;

/**
 * Renders the cluster set as a PlantUML class diagram.
 *
 *   - Each file appears as a `class` rectangle.
 *   - Each cluster appears as a `package` grouping its member files.
 *   - Pattern tags are stereotypes (`<<sql-builder>>`).
 *
 * Saves as `*.puml` — render with the standard PlantUML jar /
 * server: `java -jar plantuml.jar phpdup.puml`.
 */
final class PlantumlReporter
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
        $out  = "@startuml phpdup\n";
        $out .= "skinparam shadowing false\n";
        $out .= "skinparam linetype ortho\n";
        $out .= "skinparam classBackgroundColor #f3f4f6\n";
        $out .= "skinparam packageBackgroundColor #ecfeff\n\n";

        // Track file aliases so package members can reference them.
        $aliasFor = [];
        foreach ($report->clusters as $c) {
            foreach ($c->members as $m) {
                if (!isset($aliasFor[$m->file])) {
                    $aliasFor[$m->file] = 'F' . substr(md5($m->file), 0, 8);
                }
            }
        }
        foreach ($aliasFor as $file => $alias) {
            $out .= sprintf("class %s as \"%s\"\n", $alias, basename($file));
        }
        $out .= "\n";

        foreach ($report->clusters as $c) {
            $tags = $c->patternTags
                ? '<< ' . implode(' ', $c->patternTags) . ' >>'
                : '';
            $out .= sprintf(
                "package \"%s · impact %d\" %s {\n",
                $c->id, $c->impact, $tags,
            );
            $seen = [];
            foreach ($c->members as $m) {
                if (isset($seen[$m->file])) continue;
                $seen[$m->file] = true;
                $out .= sprintf("  %s\n", $aliasFor[$m->file]);
            }
            $out .= "}\n\n";
        }

        $out .= "@enduml\n";
        return $out;
    }
}
