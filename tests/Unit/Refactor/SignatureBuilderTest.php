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
        $this->assertStringContainsString('int $threshold,', $sig);
        $this->assertStringContainsString('string $value,', $sig);
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

        // Optional bools must default to false
        $this->assertStringContainsString('bool $includeFooBar = false,', $sig);
        $this->assertStringContainsString('bool $includeBaz = false,', $sig);

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
        $this->assertStringContainsString('bool $includeA = false,', $sig);
        $this->assertStringContainsString('bool $includeB = false,', $sig);
        $this->assertStringContainsString(': mixed', $sig);
    }

    private function hole(string $kind, string $name, string $type): Hole
    {
        $h = new Hole('__P', $kind, ['x']);
        $h->suggestedName = $name;
        $h->inferredType  = $type;
        return $h;
    }
}
