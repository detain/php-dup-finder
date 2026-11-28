<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PHPUnit\Framework\TestCase;
use Phpdup\Scanning\FileScanner;

/**
 * Item 5.2's .gitignore leg (W2a) — rule-level dialect pins below the walk:
 * line parsing (comments, negation, dir-only, anchoring, escapes, trailing
 * spaces, CRLF), bracket classes, and the two-engine (PCRE + bounded
 * automaton) agreement law inherited from once-095 for the new token kind.
 *
 * Reflection reaches the private parse/match machinery the same way the
 * shell-S21 seam reaches tokenizeGlob/regexFor — the seam exists for these
 * pins, and every case doubles as a mutation witness for the verdict logic
 * gitignoredPath() rides.
 */
final class GitIgnoreDialectTest extends TestCase
{
    /** @return list<array{negate: bool, dirOnly: bool, anchored: bool, match: array<string,mixed>}> */
    private function rules(string $body): array
    {
        $parse = new \ReflectionMethod(FileScanner::class, 'parseGitignore');
        $truncated = false;
        $rules = $parse->invokeArgs(null, [$body . "\n", &$truncated]);
        $this->assertFalse($truncated, 'single-line fixtures never trip the budget');
        return $rules;
    }

    /** @param array<string,mixed> $match */
    private function matchRule(array $match, string $subject): bool
    {
        $scan = new FileScanner([], false, [], true);
        $method = new \ReflectionMethod(FileScanner::class, 'matchesPattern');
        return $method->invoke($scan, $match, $subject);
    }

    public function testLineShapeFlags(): void
    {
        $this->assertSame([], $this->rules('# just a comment'), 'leading-# lines produce no rule');
        $this->assertSame([], $this->rules('   '));
        $this->assertSame([], $this->rules('!'));
        $this->assertSame([], $this->rules('/'));
        $this->assertSame([], $this->rules('#x.php'));

        $one = $this->rules('build/')[0];
        $this->assertTrue($one['dirOnly']);
        $this->assertFalse($one['anchored']);
        $this->assertFalse($one['negate']);

        $this->assertTrue($this->rules('!keep.log')[0]['negate']);
        $this->assertTrue($this->rules('/rootonly.php')[0]['anchored']);
        $this->assertTrue($this->rules('mid/deep.php')[0]['anchored'], 'interior slash anchors too');
        $this->assertFalse($this->rules('deep[0].php')[0]['anchored'], 'brackets are not slashes');
        $this->assertTrue($this->rules('star/*')[0]['anchored'], 'an interior slash anchors, even mid-body');

        // A slash AFTER an escape is still data, not the dir marker.
        $this->assertFalse($this->rules('weird\\/php')[0]['dirOnly']);
        // Escaped first bytes keep their literal meaning as patterns.
        $this->assertFalse($this->rules('\\!bang')[0]['negate']);
        $this->assertTrue($this->matchRule($this->rules('\\!bang')[0]['match'], '!bang'));
        $this->assertTrue($this->matchRule($this->rules('\\#sharp')[0]['match'], '#sharp'));
    }

    public function testTrailingSpaceTrimAndEscapedException(): void
    {
        $rule = $this->rules('trail.php  ')[0];
        $this->assertSame('trail.php', $rule['match']['source'], 'unescaped trailing spaces are trimmed');
        $this->assertTrue($this->matchRule($rule['match'], 'trail.php'));

        $kept = $this->rules('keepsp.php\\ ')[0];
        $this->assertSame('keepsp.php\\ ', $kept['match']['source'], 'the escaped space survives in the raw source');
        $this->assertTrue($this->matchRule($kept['match'], 'keepsp.php '), 'escaped trailing space is DATA');
        $this->assertFalse($this->matchRule($kept['match'], 'keepsp.php'), 'so the bare name is NOT matched');
    }

    public function testCrlfAndMidLineHash(): void
    {
        $parse = new \ReflectionMethod(FileScanner::class, 'parseGitignore');
        $truncated = false;
        $rules = $parse->invokeArgs(null, ["gone.php\r\nstay.php\r\n", &$truncated]);
        $this->assertCount(2, $rules);
        $this->assertSame('gone.php', $rules[0]['match']['source'], 'CR must not leak into the pattern');

        // A '#' anywhere but line start is a literal byte.
        $rule = $this->rules('a#b.php')[0];
        $this->assertTrue($this->matchRule($rule['match'], 'a#b.php'));
    }

    public function testBracketClassesMatchBytesAndNegate(): void
    {
        $lower = $this->rules('[a-z]x.php')[0];
        $this->assertTrue($this->matchRule($lower['match'], 'ax.php'));
        $this->assertTrue($this->matchRule($lower['match'], 'zx.php'));
        $this->assertFalse($this->matchRule($lower['match'], 'Ax.php'));

        $members = $this->rules('[abc]y.php')[0];
        $this->assertTrue($this->matchRule($members['match'], 'by.php'));
        $this->assertFalse($this->matchRule($members['match'], 'dy.php'));

        $neg = $this->rules('[!x]z')[0];
        $this->assertTrue($this->matchRule($neg['match'], 'az'));
        $this->assertFalse($this->matchRule($neg['match'], 'xz'));

        // leading ^ is NOT negation in git's dialect — a literal member.
        $caret = $this->rules('[^x]')[0];
        $this->assertTrue($this->matchRule($caret['match'], '^'));
        $this->assertTrue($this->matchRule($caret['match'], 'x'));
        $this->assertFalse($this->matchRule($caret['match'], 'a'));

        // ']' as FIRST member is data (git/wildmatch byte-for-byte).
        $brk = $this->rules('[]]q')[0];
        $this->assertTrue($this->matchRule($brk['match'], ']q'));

        // hyphen at class edges is a literal member.
        $edge = $this->rules('[a-]b')[0];
        $this->assertTrue($this->matchRule($edge['match'], '-b'));
        $this->assertTrue($this->matchRule($edge['match'], 'ab'));

        // unterminated '[' = literal byte (fnmatch(7) posture).
        $unterm = $this->rules('oops[.php')[0];
        $this->assertTrue($this->matchRule($unterm['match'], 'oops[.php'));

        // reversed range matches NOTHING (git's NOMATCH, negation included).
        $rev = $this->rules('[z-a]q')[0];
        $this->assertFalse($this->matchRule($rev['match'], 'aq'));
        $this->assertFalse($this->matchRule($rev['match'], 'zq'));
        $revNeg = $this->rules('[!z-a]q')[0];
        $this->assertFalse($this->matchRule($revNeg['match'], 'aq'));
    }

    public function testEscapedMetabitsBecomeLiterals(): void
    {
        $star = $this->rules('literal\\*.php')[0];
        $this->assertTrue($this->matchRule($star['match'], 'literal*.php'));
        $this->assertFalse($this->matchRule($star['match'], 'literalX.php'));

        $quest = $this->rules('q\\?mark')[0];
        $this->assertTrue($this->matchRule($quest['match'], 'q?mark'));
        $this->assertFalse($this->matchRule($quest['match'], 'qxmark'));

        $trail = $this->rules('tail\\')[0];
        $this->assertTrue($this->matchRule($trail['match'], 'tail\\'), 'a trailing lone backslash is data');
    }

    public function testGlobstarFormsAndBasenameFrames(): void
    {
        // **/'leading form: matches at any depth (one SEG group).
        $any = $this->rules('**/build/')[0];
        $this->assertTrue($this->matchRule($any['match'], 'build'));
        $this->assertTrue($this->matchRule($any['match'], 'a/b/build'));

        // trailing ** crosses segments in the pattern language; anchoring
        // comes from the interior slash (docs: 'a/**' excludes a/ subtree).
        $below = $this->rules('logs/**')[0];
        $this->assertTrue($this->matchRule($below['match'], 'logs/a/b.php'));
        $this->assertFalse($below['anchored'] === false, 'interior slash must anchor');

        // off-boundary ** collapses to one * (globstar convention == glob engine).
        $mid = $this->rules('a**b')[0];
        $this->assertTrue($this->matchRule($mid['match'], 'axxb'));
        $this->assertFalse($this->matchRule($mid['match'], 'ax/xb'));

        // unanchored patterns see the LAST SEGMENT only — slashes never
        // leak into the basename frame.
        $base = $this->rules('x.log')[0];
        $this->assertTrue($this->matchRule($base['match'], 'x.log'));
        $this->assertFalse($this->matchRule($base['match'], 'a/x.log'), 'frame is the basename; depth is the walk chain');
    }

    public function testPcreAndAutomatonEnginesAgreeOnClassShapes(): void
    {
        // backtracksSafely() refuses PCRE for >2-wildcard shapes, so this
        // goes DFA-only; cross-checked against the compiled regex itself.
        $rule = $this->rules('[ab]*c*d*e[0-9]x')[0];
        foreach (['ace0x', 'bxy', 'cde8x', 'axycddcde9x', 'acdx'] as $subject) {
            $dfa = $this->matchRule($rule['match'], $subject);
            $pcre = preg_match($rule['match']['regex'], $subject) === 1;
            $this->assertSame($pcre, $dfa, "engine drift on {$subject}");
        }
        $this->assertTrue($this->matchRule($rule['match'], 'abcddde9x'));
        $this->assertFalse($this->matchRule($rule['match'], 'acex'), 'no literal d in the subject');
    }

    public function testStarAgainstEmptyAndDotFiles(): void
    {
        // git: '*' matches '.hidden' (no special dot rules in gitignore).
        $rule = $this->rules('*')[0];
        $this->assertTrue($this->matchRule($rule['match'], '.hidden'));
        $this->assertTrue($this->matchRule($rule['match'], 'a'));
        $prefixed = $this->rules('*.php')[0];
        $this->assertTrue($this->matchRule($prefixed['match'], '.php'));
        $this->assertFalse($this->matchRule($prefixed['match'], 'php'));
    }
}
