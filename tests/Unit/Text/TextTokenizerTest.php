<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Text;

use PHPUnit\Framework\TestCase;
use Phpdup\Text\LangProfile;
use Phpdup\Text\LangProfiles;
use Phpdup\Text\TextToken;

/**
 * Item 7.1: the loose lexer contract — one flat scanner, blanks the tier
 * alphabet (ident 'I', number 'N', string 'S'), keeps keywords and
 * punctuation, drops comments per profile, and never throws.
 */
final class TextTokenizerTest extends TestCase
{
    private static function kindsOf(LangProfile $p, string $src): string
    {
        $out = [];
        foreach (\Phpdup\Text\TextTokenizer::tokenize($src, $p) as $t) {
            $out[] = $t->kind === TextToken::KIND_PUNCT ? $t->text : substr($t->kind, 0, 2);
        }
        return implode(' ', $out);
    }

    public function testJsShapeSkeletonIsKeptAndNamesBlanked(): void
    {
        $js = LangProfiles::profileFor('a.js');
        $this->assertNotNull($js);

        $one = self::kindsOf($js, 'function buyLicense(item) { return item.cost; }');
        $two = self::kindsOf($js, 'function rentWidget(part) { return part.price; }');
        $this->assertSame($one, $two, 'renames are invisible to the stream (the doc\'s buy_license ≡ buy_license2 claim)');

        $tokens = \Phpdup\Text\TextTokenizer::tokenize('function add(a, b) { return a + b; }', $js);
        $blanked = array_map(static fn (TextToken $t): string => $t->blanked, $tokens);
        $this->assertSame('function', $blanked[0], 'keywords keep their spelling');
        $this->assertContains('I', $blanked);
        $this->assertContains('+', $blanked);
    }

    public function testNumbersAndStringsCollapse(): void
    {
        $js = LangProfiles::profileFor('a.js');
        $tokens = \Phpdup\Text\TextTokenizer::tokenize("x = 42; y = 1.25; z = .5; s = 'hi'; q = \"yo\";", $js);
        $blanked = array_map(static fn (TextToken $t): string => $t->blanked, $tokens);

        $this->assertSame(3, substr_count(implode(' ', $blanked), 'N'), '42, 1.25 and .5 all blank to N');
        $this->assertSame(2, substr_count(implode(' ', $blanked), 'S'), 'both quote families blank to S');
    }

    public function testBacktickIsAStringForJsAndShellButNotPlainProfiles(): void
    {
        $js = LangProfiles::profileFor('a.js');
        $tpl = LangProfiles::profileFor('a.tpl');
        $this->assertNotNull($js);
        $this->assertNotNull($tpl);

        $tokens = \Phpdup\Text\TextTokenizer::tokenize('t = `multi\nline ok`;', $js);
        $strings = array_values(array_filter($tokens, static fn (TextToken $t): bool => $t->kind === TextToken::KIND_STRING));
        $this->assertCount(1, $strings);
        $this->assertSame('S', $strings[0]->blanked);

        // .tpl profile has backtick: false — the char is punctuation there.
        $tplTokens = \Phpdup\Text\TextTokenizer::tokenize('`x`', $tpl);
        $this->assertNotContains(TextToken::KIND_STRING, array_map(static fn (TextToken $t): string => $t->kind, $tplTokens));
    }

    public function testCommentFamiliesPerProfile(): void
    {
        $js = LangProfiles::profileFor('a.js');
        $yaml = LangProfiles::profileFor('a.yml');
        $css = LangProfiles::profileFor('a.css');
        $this->assertNotNull($js);
        $this->assertNotNull($yaml);
        $this->assertNotNull($css);

        $this->assertSame('id id', self::kindsOf($js, "// gone\nid id"), 'js line comment dropped');
        $this->assertSame('id id', self::kindsOf($js, '/* gone */ id id'), 'js block comment dropped');
        $this->assertSame('# id id', self::kindsOf($js, '# id id'), '# is NOT a comment in js');

        $this->assertSame('id id', self::kindsOf($yaml, "# gone\nid id"), 'yaml # comment dropped');
        $this->assertSame('/ / id id id', self::kindsOf($css, '// gone id id'), 'css has no // door — two punct tokens instead');
        $this->assertSame('id id', self::kindsOf($css, '/* gone */ id id'), 'css block comment dropped');
    }

    public function testUnterminatedStringEndsAtLineAndTruncatedCommentEndsAtEof(): void
    {
        $js = LangProfiles::profileFor('a.js');
        $tokens = \Phpdup\Text\TextTokenizer::tokenize("s = 'oops\nn = 1;", $js);
        $kinds = array_map(static fn (TextToken $t): string => $t->kind, $tokens);
        $this->assertContains(TextToken::KIND_STRING, $kinds);
        $this->assertContains(TextToken::KIND_NUMBER, $kinds, 'the next line still lexes (line bumped correctly)');

        $truncated = \Phpdup\Text\TextTokenizer::tokenize('a /* never closes', $js);
        $this->assertSame(['I'], array_map(static fn (TextToken $t): string => $t->blanked, $truncated));
    }

    public function testEmptyAndWhitespaceInputLexToNothing(): void
    {
        $js = LangProfiles::profileFor('a.js');
        $this->assertSame([], \Phpdup\Text\TextTokenizer::tokenize('', $js));
        $this->assertSame([], \Phpdup\Text\TextTokenizer::tokenize(" \t\n", $js));
    }

    public function testLineTrackingAcrossNewlines(): void
    {
        $js = LangProfiles::profileFor('a.js');
        $tokens = \Phpdup\Text\TextTokenizer::tokenize("a\n\nb\n  c", $js);
        $this->assertSame([1, 3, 4], array_map(static fn (TextToken $t): int => $t->line, $tokens));
    }

    public function testKeywordCaseFoldingKeepsTwinCaseVariantsIdentical(): void
    {
        $js = LangProfiles::profileFor('a.js');
        $a = array_map(static fn (TextToken $t): string => $t->blanked, \Phpdup\Text\TextTokenizer::tokenize('RETURN x;', $js));
        $b = array_map(static fn (TextToken $t): string => $t->blanked, \Phpdup\Text\TextTokenizer::tokenize('return x;', $js));
        $this->assertSame($a, $b);
    }

    public function testTwigKeywordsSurviveTemplateBlanking(): void
    {
        $tpl = LangProfiles::profileFor('a.tpl');
        $this->assertNotNull($tpl);
        $blanked = array_map(
            static fn (TextToken $t): string => $t->blanked,
            \Phpdup\Text\TextTokenizer::tokenize('{% if a.b == "x" %}{% endif %}', $tpl),
        );
        $this->assertContains('if', $blanked);
        $this->assertContains('endif', $blanked);
    }

    public function testProfileTableCoversTheDocFamilies(): void
    {
        foreach (['x.js', 'x.mjs', 'x.jsx', 'x.ts', 'x.tpl', 'x.twig', 'x.yml', 'x.yaml', 'x.sh', 'x.css', 'x.html', 'x.json'] as $path) {
            $this->assertNotNull(LangProfiles::profileFor($path), $path . ' must have a profile');
        }
        $this->assertNull(LangProfiles::profileFor('x.php'));
        $this->assertNull(LangProfiles::profileFor('x.txt'));
        $this->assertNull(LangProfiles::profileFor('noext'));
        $this->assertContains('js', LangProfiles::extensions());
    }
}
