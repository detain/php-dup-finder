<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\TimeseriesReporter;
use Phpdup\Util\Json;

/**
 * Contract of the shared JSON encoder behind every report writer
 * (php-dup-finder-output-012).
 *
 * The bug it kills: a `json_encode` that returns `false` on hostile input,
 * then flows as a `false` into a `string` report writer (a crash mid-run) or,
 * in the append-only timeseries case, a `(string)` cast that silently writes
 * an empty line. The helper must therefore never hand back `false` or an
 * empty payload — it substitutes invalid UTF-8 and throws a named exception
 * on values that no substitution can save.
 */
final class JsonTest extends TestCase
{
    public function testCleanPayloadIsByteIdenticalToNativeEncode(): void
    {
        // Goldens depend on this: adding the safety flags must not change a
        // single byte for a well-formed payload.
        $clean = [
            'phpdup_version' => '0.1.0',
            'summary'        => ['files' => 3, 'duplicated_lines' => 42],
            'clusters'       => [
                ['id' => 'abc', 'file' => 'src/Foo.php', 'similarity' => 0.95],
            ],
            'nested'         => ['slash' => 'a/b/c', 'utf8' => 'naïve ✓'],
        ];
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

        $this->assertSame(
            json_encode($clean, $flags),
            Json::encode($clean, $flags),
            'safety flags must not perturb a clean payload',
        );
    }

    public function testCompactEncodingKeepsNativeBytesForCleanScalars(): void
    {
        // The timeseries path (JSON_UNESCAPED_SLASHES, no pretty print).
        $record = ['files' => 0, 'clusters' => 0, 'pattern_tags' => []];

        $this->assertSame(
            json_encode($record, JSON_UNESCAPED_SLASHES),
            Json::encode($record, JSON_UNESCAPED_SLASHES),
        );
    }

    public function testInvalidUtf8InAFieldIsSubstitutedNotFalseOrEmpty(): void
    {
        // "\xB1\x31" is invalid UTF-8 — the exact shape a raw FS path byte or
        // an embedded observed literal can carry. A plain encode returns false;
        // the helper must return a still-valid, non-empty record instead.
        $payload = ['file' => "src/\xB1\x31-Foo.php", 'impact' => 5];
        $encoded = Json::encode($payload, JSON_UNESCAPED_SLASHES);

        $this->assertNotSame('', $encoded);
        $this->assertNotSame('false', $encoded);

        $decoded = json_decode($encoded, true);
        $this->assertIsArray($decoded);
        // The bad byte is replaced by U+FFFD, the surrounding bytes survive.
        $this->assertStringContainsString("\u{FFFD}", (string) $decoded['file']);
        $this->assertSame(5, $decoded['impact']);
    }

    public function testTopLevelInvalidUtf8SubstitutesUnderDefaultFlags(): void
    {
        // Safety flags cannot be opted out by passing flags=0.
        $encoded = Json::encode("\xB1\x31");

        $this->assertNotSame('false', $encoded);
        $this->assertNotSame('', $encoded);
        $this->assertStringContainsString('\ufffd', $encoded);
    }

    public function testTimeseriesReportWithHostileNameEmitsSubstitutedLineNotEmpty(): void
    {
        // The real TimeseriesReporter code path (php-dup-finder-output-012's
        // worse-in-kind case): the old `(string) json_encode(...)` returned
        // an EMPTY line for a hostile commit sha, silently corrupting the
        // append-only JSONL. Through the shared encoder it must emit a
        // substituted, decodable record instead — never an empty line.
        $reporter = new TimeseriesReporter(commitSha: "\xFF\xFEhostile-machine-name", timestamp: 1700000000);
        $line     = $reporter->buildLine(new Report(10, 42, 0, [], Config::defaults([])));

        $this->assertNotSame('', $line, 'a hostile name must never collapse the row to an empty write');
        $this->assertNotSame('false', $line);

        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded, 'the substituted line must still be valid JSON');
        $this->assertStringContainsString("\u{FFFD}", (string) $decoded['commit_sha']);
        $this->assertSame(10, $decoded['files']);
        $this->assertSame(42, $decoded['blocks']);
    }

    public function testUnencodableValueThrowsNamedException(): void
    {
        // A resource has no JSON representation and substitution cannot save
        // it — the encoder must fail loudly with a named exception rather
        // than return a value that would TypeError a report writer.
        $handle = fopen('php://memory', 'rb');
        $this->assertNotFalse($handle);
        try {
            $thrown = null;
            try {
                Json::encode(['report' => ['handle' => $handle]], JSON_UNESCAPED_SLASHES);
            } catch (\JsonException $e) {
                $thrown = $e;
            }
            $this->assertInstanceOf(\JsonException::class, $thrown);
            $this->assertStringContainsString('phpdup:', (string) $thrown?->getMessage());
        } finally {
            fclose($handle);
        }
    }

    public function testThrowsAreSelfDocumentingAndChainTheNativeReason(): void
    {
        $handle = fopen('php://temp', 'rb');
        $this->assertNotFalse($handle);
        try {
            Json::encode($handle);
            $this->fail('expected JsonException for an unencodable resource');
        } catch (\JsonException $e) {
            $this->assertNotSame('', $e->getMessage());
            $this->assertStringContainsString('no JSON representation', $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        } finally {
            fclose($handle);
        }
    }
}
