<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Util\ControlText;
use Phpdup\Util\Version;

/**
 * Checkstyle-format XML — consumed by countless CI integrations
 * (Jenkins Warnings NG, Bitbucket reports, Sonar, etc.).
 */
final class CheckstyleReporter
{
    use WritesReportFile;

    /**
     * Bytes XML 1.0 forbids anywhere in a document (its `Char` production is
     * #x9 | #xA | #xD | [#x20-#xD7FF] | … — every other C0 control is out).
     * All of them are legal ext4 filename bytes, so a scanned path can carry
     * one straight into an attribute. htmlspecialchars does NOT filter them
     * even with ENT_XML1 (probed: "a\x01b" passes through byte-identical),
     * and a single raw one poisons the WHOLE document — xmllint/libxml
     * reject the parse and every entry in the report dies with it
     * (php-dup-finder-once-067 / TPL-02). Tab, LF and CR are deliberately
     * NOT in this set: they are legal XML whitespace (attribute values fold
     * them to spaces per spec, which once-S58's defuse pins observe), and
     * the substitution would erase evidence they carry without that need.
     */
    private const XML_FORBIDDEN_BYTES =
        "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";

    public function writeTo(Report $report, string $file): void
    {
        $this->writeFile($file, $this->build($report));
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
        $xml .= "<checkstyle version=\"phpdup-" . Version::STRING . "\">\n";
        foreach ($byFile as $file => $entries) {
            $xml .= sprintf("  <file name=\"%s\">\n", $this->escape($file));
            foreach ($entries as [$cluster, $member]) {
                /** @var Cluster $cluster */
                /** @var Block $member */
                $xml .= sprintf(
                    "    <error line=\"%d\" column=\"1\" severity=\"%s\" message=\"%s\" source=\"phpdup.duplicate-logic\"/>\n",
                    $member->range->start,
                    Severity::checkstyleSeverity($cluster->exact),
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

    /**
     * Cell escaper for the two spreadsheet-bound cells of the format: the
     * `<file name=…>` attribute and the `<error message=…>` attribute (whose
     * leading text is the scanned path).
     *
     * once-064 (S58): every CSV-bound surface defuses through the single
     * shared venue — Checkstyle reports are imported into spreadsheets by
     * CI plugins exactly like the CSV output, and both cells can LEAD with
     * attacker-influenced filename bytes. The defuse runs BEFORE the XML
     * escape so the `'` marker is carried inside the cell value (round-trips
     * faithfully through ENT_QUOTES), never as injected markup.
     *
     * once-067 (S60): two-byte-class closure on top of the same venue.
     * (1) Invalid UTF-8 in a filename made the WHOLE escaped value vanish —
     * htmlspecialchars without ENT_SUBSTITUTE returns '' on an illegal
     * sequence (TPL-02) — producing silently identity-less `name=""`
     * entries that collide with each other in every CI importer.
     * (2) XML-forbidden C0 control bytes rode through raw and broke the
     * document for everyone (see {@see XML_FORBIDDEN_BYTES}).
     * Both are now handled the way the ControlText venue handles an
     * uncarriable byte: one visible U+FFFD per offending byte, never a
     * silent value drop. Forbidden bytes are substituted BEFORE the defuse
     * so the venue inspects the bytes the cell actually carries (none of
     * this class is a formula lead, so clean and lead-marked values are
     * unaffected either way).
     */
    private function escape(string $s): string
    {
        $s = strtr($s, array_fill_keys(str_split(self::XML_FORBIDDEN_BYTES), ControlText::SUBSTITUTE));
        return htmlspecialchars(CsvReporter::defuseFormula($s), ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
