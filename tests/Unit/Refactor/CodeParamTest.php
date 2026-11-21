<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use Phpdup\Refactor\CodeParam;
use PHPUnit\Framework\TestCase;

/**
 * The '$'-boundary and type-vocabulary projector (r2 N-1). These cases are
 * the exact shapes the real-corpus census produced: sigil-carrying names,
 * class-string types, $this, sanitizer collisions, array/generic spellings.
 */
final class CodeParamTest extends TestCase
{
    public function testSigilPassthroughIsIdentityForProductionNames(): void
    {
        $this->assertSame('$threshold', CodeParam::varName('$threshold', '__P0'));
        $this->assertSame('threshold', CodeParam::bareName('$threshold', '__P0'));
    }

    public function testMissingSigilIsAddedOnce(): void
    {
        $this->assertSame('$userId', CodeParam::varName('userId', '__P0'));
    }

    public function testEmptySuggestedFallsBackToPlaceholder(): void
    {
        $this->assertSame('$__P7', CodeParam::varName('', '__P7'));
    }

    public function testThisIsTheOnlyForbiddenBindingAndGoesToPlaceholder(): void
    {
        $this->assertSame('$__P9', CodeParam::varName('$this', '__P9'));
        // placeholder also 'this' → generic terminal, still legal:
        $this->assertSame('$param', CodeParam::varName('$this', 'this'));
    }

    public function testPlainClassVariableNameStaysUntouched(): void
    {
        // Measured truth: the corpus 'unexpected token class' deaths came
        // from the TYPE class-string, not from a variable named $class.
        $this->assertSame('$class', CodeParam::varName('$class', '__P0'));
    }

    public function testIllegalCharactersSanitizeAndLeadingDigitGetsPrefix(): void
    {
        $this->assertSame('$foo_bar', CodeParam::varName('$foo.bar', '__P0'));
        $this->assertSame('$_2nd', CodeParam::varName('$2nd', '__P0'));
    }

    public function testUniqueWithinListIsDeterministic(): void
    {
        $used = [];
        $this->assertSame('$x', CodeParam::uniqueWithin('$x', $used));
        $this->assertSame('$x_2', CodeParam::uniqueWithin('$x', $used));
        $this->assertSame('$x_3', CodeParam::uniqueWithin('$x', $used));
        $this->assertSame('$y', CodeParam::uniqueWithin('$y', $used));
    }

    public function testTypeTextMapsEveryVocabularyParameterSynthesizerEmits(): void
    {
        $this->assertSame('', CodeParam::typeText(''));
        $this->assertSame('string', CodeParam::typeText('class-string'));
        $this->assertSame('mixed', CodeParam::typeText('null'));
        $this->assertSame('?int', CodeParam::typeText('int|null'));
        $this->assertSame('?string', CodeParam::typeText('string|null'));
        $this->assertSame('mixed', CodeParam::typeText('string|int|null'));
        $this->assertSame('callable|string', CodeParam::typeText('callable|string'));
        $this->assertSame('int', CodeParam::typeText('int'));
        $this->assertSame('bool', CodeParam::typeText('bool'));
    }

    public function testTypeTextHardensShapesBeyondTheKnownVocabulary(): void
    {
        $this->assertSame('array', CodeParam::typeText('int[]'));
        $this->assertSame('array', CodeParam::typeText('array<int, string>'));
        $this->assertSame('array', CodeParam::typeText('non-empty-array'));
        $this->assertSame('string', CodeParam::typeText('numeric-string'));
        $this->assertSame('bool', CodeParam::typeText('true'));
        $this->assertSame('string', CodeParam::typeText('class-string|string'), 'dedupe: duplicate union members do not parse');
        $this->assertSame('mixed', CodeParam::typeText('void'));
        $this->assertSame('mixed', CodeParam::typeText('?!@#$'));
        $this->assertSame('Acme\\Thing', CodeParam::typeText('Acme\\Thing'));
    }

    public function testEveryTypeTextOutputParsesInTheParameterPosition(): void
    {
        $inputs = ['class-string', 'null', 'int|null', 'string|int|null', 'callable|string',
                   'int[]', 'array<int, string>', 'non-empty-array', 'numeric-string', 'true',
                   'class-string|string', 'void', '?!@#$', 'Acme\\Thing', ''];
        $tmp = sys_get_temp_dir() . '/phpdup-cp-parse-' . uniqid() . '.php';
        $failures = [];
        try {
            foreach ($inputs as $in) {
                $type = CodeParam::typeText($in) ?: 'mixed';
                $code = "<?php\nfunction census_pin({$type} \$v) { return \$v; }\n";
                file_put_contents($tmp, $code);
                $out = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
                if (!str_contains((string)$out, 'No syntax errors')) {
                    $failures[] = "{$in} -> {$type}";
                }
            }
        } finally {
            @unlink($tmp);
        }
        $this->assertSame([], $failures, 'projected types must parse in code position');
    }
}
