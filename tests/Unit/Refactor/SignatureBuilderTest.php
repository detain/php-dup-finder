<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\SignatureBuilder;

final class SignatureBuilderTest extends TestCase
{
    public function testEmptyClusterFallsBackToExtractedFunction(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        (new SignatureBuilder())->buildSignature($cluster);

        $this->assertNotNull($cluster->signature);
        $this->assertStringContainsString('extractedFunction', (string)$cluster->signature);
    }

    public function testRequiredParamsRenderInOrder(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = [
            $this->hole('literal', '$threshold', 'int'),
            $this->hole('literal', '$value', 'string'),
        ];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;
        // output-S37 re-pin: the builder renders the whole parameter list on
        // ONE line — from the old contains-pins 'int $threshold,' /
        // 'string $value,' (multi-line, trailing commas) to the exact
        // single-line truth.
        $this->assertSame('function extractedFunction(int $threshold, string $value): mixed', $sig);
        // required params must come before any defaulted ones
        $this->assertLessThan(strpos($sig, '$value'), strpos($sig, '$threshold'));
    }

    public function testOptionalBlockHolesRenderAsDefaultedBoolsAfterRequired(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = [
            $this->hole('optional_block', '$includeFooBar', 'bool'),
            $this->hole('literal', '$value', 'string'),
            $this->hole('optional_block', '$includeBaz', 'bool'),
        ];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;

        // output-S37 re-pin: from the old multi-line contains-pins
        // 'bool $includeFooBar = false,' / 'bool $includeBaz = false,' to the
        // exact single-line truth (defaults keep the ', ' join, no trailing
        // comma).
        $this->assertSame(
            'function extractedFunction(string $value, bool $includeFooBar = false, bool $includeBaz = false): mixed',
            $sig
        );

        // PHP requires defaulted params after required ones — verify by
        // string position.
        $valuePos     = strpos($sig, '$value');
        $fooBarPos    = strpos($sig, '$includeFooBar');
        $bazPos       = strpos($sig, '$includeBaz');
        $this->assertNotFalse($valuePos);
        $this->assertNotFalse($fooBarPos);
        $this->assertNotFalse($bazPos);
        $this->assertLessThan($fooBarPos, $valuePos, 'required must come before optional');
        $this->assertLessThan($bazPos,    $valuePos, 'required must come before optional');
    }

    public function testOptionalBlockOnlyClusterStillHasValidSignature(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = [
            $this->hole('optional_block', '$includeA', 'bool'),
            $this->hole('optional_block', '$includeB', 'bool'),
        ];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;
        // output-S37 re-pin: multi-line contains-pins with trailing commas →
        // exact single-line truth (': mixed' is subsumed).
        $this->assertSame('function extractedFunction(bool $includeA = false, bool $includeB = false): mixed', $sig);
    }

    public function testClassStringTypeRendersAsString(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = [
            $this->hole('name', '$model', 'class-string'),
        ];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;
        // output-S37 re-pin: 'string $model,' (multi-line contains) → exact
        // single-line truth.
        $this->assertSame('function extractedFunction(string $model): mixed', $sig);
        $this->assertStringNotContainsString('class-string', $sig);
    }

    public function testNullOnlyTypeRendersAsMixed(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = [
            $this->hole('literal', '$value', 'null'),
        ];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;
        // output-S37 re-pin: 'mixed $value,' (multi-line contains) → exact single-line truth.
        $this->assertSame('function extractedFunction(mixed $value): mixed', $sig);
        $this->assertStringNotContainsString('null $value', $sig);
    }

    public function testNullableSingleTypeRendersAsNullableShorthand(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = [
            $this->hole('literal', '$value', 'int|null'),
        ];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;
        // output-S37 re-pin: '?int $value,' (multi-line contains) → exact single-line truth.
        $this->assertSame('function extractedFunction(?int $value): mixed', $sig);
        $this->assertStringNotContainsString('|null', $sig);
    }

    public function testNullableClassStringRendersAsNullableString(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = [
            $this->hole('name', '$model', 'class-string|null'),
        ];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;
        // output-S37 re-pin: '?string $model,' (multi-line contains) → exact single-line truth.
        $this->assertSame('function extractedFunction(?string $model): mixed', $sig);
        $this->assertStringNotContainsString('class-string', $sig);
    }

    public function testMultiTypeUnionWithNullRendersAsMixed(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = [
            $this->hole('literal', '$value', 'int|string|null'),
        ];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;
        // output-S37 re-pin: 'mixed $value,' (multi-line contains) → exact single-line truth.
        $this->assertSame('function extractedFunction(mixed $value): mixed', $sig);
        $this->assertStringNotContainsString('|null', $sig);
    }

    public function testSynthesizedSignaturePassesPhpLint(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = [
            $this->hole('name', '$model', 'class-string'),
            $this->hole('literal', '$value', 'null'),
            $this->hole('literal', '$threshold', 'int|null'),
            $this->hole('optional_block', '$includeFlag', 'bool'),
        ];

        (new SignatureBuilder())->buildSignature($cluster);

        $sig = (string)$cluster->signature;
        $php = "<?php\ndeclare(strict_types=1);\n\n" . $sig . "{}\n";

        $tmpFile = sys_get_temp_dir() . '/phpdup_sig_test_' . uniqid() . '.php';
        file_put_contents($tmpFile, $php);

        $output = shell_exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1');
        unlink($tmpFile);

        $this->assertStringContainsString('No syntax errors', $output, 'Synthesized signature must be valid PHP');
    }

    /**
     * output-S37 (php-dup-finder-output-058) contract: a rendered signature
     * is a SINGLE line — no embedded newlines, no indent, no trailing comma.
     * Multi-line "pretty" shapes belong to the display side (reporters wrap);
     * the stored value stays one field, one line.
     */
    public function testRenderedSignaturesAreSingleLine(): void
    {
        $shapes = [
            'multi-hole' => [
                $this->hole('literal', '$threshold', 'int'),
                $this->hole('literal', '$value', 'string'),
                $this->hole('optional_block', '$includeFlag', 'bool'),
            ],
            'promoted-free' => [
                $this->hole('literal', '$value', 'string'),
            ],
        ];

        foreach ($shapes as $label => $holes) {
            $cluster = new Cluster('TEST', [], 1.0, false);
            $cluster->holes = $holes;
            (new SignatureBuilder())->buildSignature($cluster);
            $sig = (string)$cluster->signature;

            $this->assertStringNotContainsString("\n", $sig, $label . ': signature must carry no line break');
            $this->assertStringNotContainsString('    ', $sig, $label . ': signature must carry no indent');
            $this->assertSame(
                0,
                preg_match('/,\s*\): mixed$/', $sig),
                $label . ': last parameter must not keep a trailing comma'
            );
            $this->assertMatchesRegularExpression('/^function [A-Za-z_][A-Za-z0-9_]*\(.*\): mixed$/', $sig);
        }
    }

    /**
     * output-S37 + output-S57 r1 doctrine: triviality survives the de-flatten.
     * A zero-hole cluster (and a CAP_SENTINEL-only cluster, whose sentinel is
     * skipped) renders the bare one-line signature — RefactorStage's
     * zero-hole routing depends on it staying exact.
     */
    public function testZeroHoleClusterRendersBareTrivialSignature(): void
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        (new SignatureBuilder())->buildSignature($cluster);

        $this->assertSame('function extractedFunction(): mixed', $cluster->signature);
    }

    private function hole(string $kind, string $name, string $type): Hole
    {
        $h = new Hole('__P', $kind, ['x']);
        $h->suggestedName = $name;
        $h->inferredType  = $type;
        return $h;
    }
}
