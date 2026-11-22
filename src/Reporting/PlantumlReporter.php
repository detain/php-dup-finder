<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Util\ControlText;

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
    use WritesReportFile;

    public function writeTo(Report $report, string $file): void
    {
        $this->writeFile($file, $this->build($report));
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
                // output-047: the basename lands inside a quoted `class … as
                // "…"` label and PlantUML grammar carries NO escape for a
                // double quote — a `"` + newline pair in a scanned name
                // closes the string and opens top-level `!` preprocessor
                // directives (`!include` ⇒ file read / SSRF at render time,
                // SEC-05 proof). Control-byte paths are refused outright at
                // this boundary (reject arm); a bare quote is softened to an
                // apostrophe in label() so no name can ever close its label.
                ControlText::reject($m->file, 'PlantumlReporter', 'member file path');
                if (!isset($aliasFor[$m->file])) {
                    $aliasFor[$m->file] = 'F' . substr(md5($m->file), 0, 8);
                }
            }
        }
        foreach ($aliasFor as $file => $alias) {
            $out .= sprintf("class %s as \"%s\"\n", $alias, $this->label($file));
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

    /**
     * Render a scanned path as a PlantUML-safe quoted label: the basename,
     * with a literal double quote softened to an apostrophe (the only way
     * to keep a name from closing its own label in this grammar) and any
     * surviving control byte substituted visibly — belt behind the
     * boundary reject in build().
     */
    private function label(string $file): string
    {
        return ControlText::substitute(str_replace('"', "'", basename($file)));
    }
}
