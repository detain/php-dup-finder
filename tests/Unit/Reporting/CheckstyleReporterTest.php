<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\CheckstyleReporter;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;

final class CheckstyleReporterTest extends TestCase
{
    public function testEmptyReportProducesValidEmptyXml(): void
    {
        $xml = (new CheckstyleReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<checkstyle version="phpdup-', $xml);
        $this->assertStringContainsString('</checkstyle>', $xml);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
    }

    /**
     * php-dup-finder-output-036 (output-S56a migration): this arm used to run
     * the four pipeline stages over tests/Fixtures/sql to obtain its Report.
     * Per the AGENTS.md reporter-test convention it now consumes a
     * hand-built Report — same emission surface: parseable XML, one
     * `<error>` per cluster member, the constant source cell, and a
     * non-empty line attribute on every entry. The retired sql fixture was
     * a single file, so the `<file>`-grouping leg only ever ran in its
     * degenerate 1-group shape; the synthetic report pins multi-file
     * grouping and the shared-file 2-error group explicitly, and the
     * member/effect counts are hard-coded here instead of being re-derived
     * from the very report handed to the emitter (disclosed strengthenings,
     * assertions carried across none weakened).
     */
    public function testFixtureReportProducesParseableXmlWithErrorPerMember(): void
    {
        $member = static fn(string $file, int $line): Block => new Block(
            file: $file,
            range: new LineRange($line, $line + 4),
            kind: 'function',
            namespace: null,
            class: null,
            name: 'm',
            ast: new \PhpParser\Node\Stmt\Nop(),
        );

        $shared = 'checkstyle-shared.php';
        $exact  = new Cluster('F0000000000000009', [$member($shared, 10), $member($shared, 30)], 1.0, true);
        $exact->impact = 10;
        $near  = new Cluster('F0000000000000010', [$member('checkstyle-alone.php', 5)], 0.9, false);
        $near->impact = 5;
        $far   = new Cluster('F0000000000000011', [$member('checkstyle-third.php', 1)], 0.8, false);
        $far->impact = 5;

        $xml = (new CheckstyleReporter())->build(
            new Report(3, 4, 0, [$exact, $near, $far], Config::defaults([])),
        );

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));

        $errors = $doc->getElementsByTagName('error');
        // One <error> per member — four members, four errors (was: > 0).
        $this->assertCount(4, $errors);
        $lines = [];
        foreach ($errors as $err) {
            $this->assertSame('phpdup.duplicate-logic', $err->getAttribute('source'));
            $this->assertNotEmpty($err->getAttribute('line'));
            $lines[] = $err->getAttribute('line');
        }
        // Emission walks clusters in order, so the line cells map the
        // member ranges 1:1 (was: presence-only).
        $this->assertSame(['10', '30', '5', '1'], $lines);

        // File grouping: three distinct paths, the shared file carrying two.
        $files = [];
        foreach ($doc->getElementsByTagName('file') as $fileNode) {
            $files[$fileNode->getAttribute('name')]
                = $fileNode->getElementsByTagName('error')->length;
        }
        $this->assertSame(
            [$shared => 2, 'checkstyle-alone.php' => 1, 'checkstyle-third.php' => 1],
            $files,
            'members of one file group under a single <file> node',
        );

        // Message-cell format leg the fixture report implicitly exercised
        // (byte hygiene of the cell is pinned by the once-S60 arms below).
        $first = $errors->item(0);
        $this->assertInstanceOf(\DOMElement::class, $first);
        $this->assertStringStartsWith(
            $shared . ':10-14 duplicates cluster F0000000000000009 (2 members, similarity 1.00, impact 10)',
            $first->getAttribute('message'),
        );
    }

    /**
     * php-dup-finder-output-037: `<error severity="...">` routes through
     * Severity::checkstyleSeverity while the emitted attribute stays identical
     * to the pre-centralization inline `$cluster->exact ? 'warning' : 'info'`
     * — the expected literals below are that old expression, kept verbatim as
     * a byte-freeze oracle independent of the center.
     */
    public function testErrorSeverityRoutesThroughSeverityWithByteIdenticalLabels(): void
    {
        $member = static fn(string $name, int $line): Block => new Block(
            file: 'checkstyle-level.php',
            range: new LineRange($line, $line + 4),
            kind: 'function',
            namespace: null,
            class: null,
            name: $name,
            ast: new \PhpParser\Node\Stmt\Nop(),
        );

        $exact = new Cluster('F0000000000000005', [$member('a', 1)], 1.0, true);
        $near  = new Cluster('F0000000000000006', [$member('b', 10)], 0.8, false);

        $xml = (new CheckstyleReporter())->build(
            new Report(1, 2, 0, [$exact, $near], Config::defaults([])),
        );

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));

        $severities = [];
        foreach ($doc->getElementsByTagName('error') as $err) {
            $severities[] = $err->getAttribute('severity');
        }
        $this->assertSame(['warning', 'info'], $severities,
            'exact clone keeps warning, near-dup keeps info — the historical inline mapping');
    }

    /**
     * php-dup-finder-once-067 (once-S60): the escape venue used to run
     * htmlspecialchars WITHOUT ENT_SUBSTITUTE (invalid UTF-8 → the whole
     * attribute value vanished → silently identity-less `name=""` entries
     * colliding in every CI importer) and with raw XML-forbidden C0 control
     * bytes (legal ext4 name bytes) poisoning the WHOLE document (TPL-02:
     * xmllint rejects; every entry dies with it). Posture now: one visible
     * U+FFFD per offending byte, document always strict-parseable, every
     * entry keeps a non-empty identity cell.
     */
    public function testForbiddenControlAndLatin1BytesProduceStrictXmlWithNamedEntries(): void
    {
        $latin1 = '/scan/caf' . "\xE9" . '.php';
        $us     = '/scan/a' . "\x01" . 'b.php';
        $nul    = '/scan/x' . "\x00" . 'y.php';
        $esc    = '/scan/e' . "\x1B" . 'f.php';
        $vt     = '/scan/v' . "\x0B" . 'w.php';
        $fs     = '/scan/f' . "\x0C" . 'g.php';
        $tabbed = '/scan/tab' . "\x09" . 'legal.php';
        $paths  = [$latin1, $us, $nul, $esc, $vt, $fs, $tabbed, '/scan/plain.php'];

        $members = [];
        foreach ($paths as $i => $p) {
            $members[] = new Block(
                file: $p,
                range: new LineRange($i + 1, $i + 5),
                kind: 'function',
                namespace: null,
                class: null,
                name: 'm',
                ast: new \PhpParser\Node\Stmt\Nop(),
            );
        }
        $cluster = new Cluster('F0000000000000007', $members, 0.9, false);
        $xml     = (new CheckstyleReporter())->build(
            new Report(1, count($paths), 0, [$cluster], Config::defaults([])),
        );

        // (1) Well-formedness first: NONE of the 29 XML 1.0-forbidden bytes
        // may survive into the emitted document, and a strict parse must
        // succeed (the old raw \x01 made loadXML/xmllint reject everything).
        $this->assertSame(0, preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $xml));
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'one bad byte must not kill the document again');

        // (2) Identity: every <file> keeps a non-empty, distinguishable
        // name cell; each offending byte arrives as one visible U+FFFD.
        $sub   = "\u{FFFD}";
        $names = [];
        foreach ($doc->getElementsByTagName('file') as $fileNode) {
            $name = $fileNode->getAttribute('name');
            $this->assertNotSame('', $name, 'empty-name entry — the identity-less collision class is back');
            $names[] = $name;
        }
        $this->assertSame([
            '/scan/caf' . $sub . '.php',
            '/scan/a' . $sub . 'b.php',
            '/scan/x' . $sub . 'y.php',
            '/scan/e' . $sub . 'f.php',
            '/scan/v' . $sub . 'w.php',
            '/scan/f' . $sub . 'g.php',
            // Tab is legal XML whitespace (deliberately NOT substituted);
            // attribute-value normalization folds it to a space on parse —
            // spec behaviour the once-S58 defuse pins already observe.
            '/scan/tab legal.php',
            '/scan/plain.php',
        ], $names);
        $this->assertCount(8, array_unique($names), 'entries must stay distinguishable');

        // (3) The message cell carries the same path through the same venue.
        $firstError = $doc->getElementsByTagName('error')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $firstError);
        $message = $firstError->getAttribute('message');
        $this->assertNotSame('', $message, 'empty message — data loss again');
        $this->assertStringStartsWith('/scan/caf' . $sub . '.php:1-5 duplicates cluster F0000000000000007', $message);

        // (4) Clean paths are untouched byte-for-byte — the upgrade must
        // not re-encode anything that was already valid XML text.
        $this->assertStringContainsString('<file name="/scan/plain.php">', $xml);
    }

    /**
     * Formula-lead interplay with the byte-class arm (the S58 venue must
     * keep deciding on the bytes the cell actually carries): a lead byte
     * from the substituted class is NOT a formula lead before or after,
     * while a genuine `=` lead behind an offending byte keeps its marker.
     */
    public function testDefuseVenueSeesSubstitutedBytesWithUnchangedDecisions(): void
    {
        $members = [];
        foreach (["\x01" . '=cmd.php', "\t=cmd.php", "\x1B" . '.php'] as $i => $p) {
            $members[] = new Block(
                file: $p, range: new LineRange(1, 5), kind: 'function',
                namespace: null, class: null, name: 'm', ast: new \PhpParser\Node\Stmt\Nop(),
            );
        }
        $xml = (new CheckstyleReporter())->build(
            new Report(1, 3, 0, [new Cluster('F0000000000000008', $members, 0.9, false)], Config::defaults([])),
        );
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));

        $sub  = "\u{FFFD}";
        $names = [];
        foreach ($doc->getElementsByTagName('file') as $fileNode) {
            $names[] = $fileNode->getAttribute('name');
        }
        // \x01 lead → substituted; FFFD is not a formula lead, no `'` marker.
        $this->assertSame($sub . '=cmd.php', $names[0]);
        // Tab lead is legal whitespace and a real formula lead: marker
        // stays, tab rides raw and folds to a space on parse.
        $this->assertStringStartsWith("' ", $names[1], 'tab-leading value must keep its defuse marker');
        // ESC lead → substituted, value identity kept (no XML poisoning).
        $this->assertSame($sub . '.php', $names[2]);
    }
}
