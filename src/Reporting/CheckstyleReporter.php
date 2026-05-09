<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * Checkstyle-format XML — consumed by countless CI integrations
 * (Jenkins Warnings NG, Bitbucket reports, Sonar, etc.).
 */
final class CheckstyleReporter
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
        $byFile = [];
        foreach ($report->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $byFile[$member->file][] = [$cluster, $member];
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<checkstyle version=\"phpdup-0.2.0\">\n";
        foreach ($byFile as $file => $entries) {
            $xml .= sprintf("  <file name=\"%s\">\n", $this->escape($file));
            foreach ($entries as [$cluster, $member]) {
                /** @var Cluster $cluster */
                /** @var Block $member */
                $xml .= sprintf(
                    "    <error line=\"%d\" column=\"1\" severity=\"%s\" message=\"%s\" source=\"phpdup.duplicate-logic\"/>\n",
                    $member->range->start,
                    $cluster->exact ? 'warning' : 'info',
                    $this->escape(sprintf(
                        '%s:%d-%d duplicates cluster %s (%d members, similarity %.2f, impact %d)',
                        $member->file,
                        $member->range->start,
                        $member->range->end,
                        $cluster->id,
                        count($cluster->members),
                        $cluster->similarity,
                        $cluster->impact,
                    )),
                );
            }
            $xml .= "  </file>\n";
        }
        $xml .= "</checkstyle>\n";
        return $xml;
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
