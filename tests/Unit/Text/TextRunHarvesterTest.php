<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Text;

use PHPUnit\Framework\TestCase;
use Phpdup\Text\LangProfiles;
use Phpdup\Text\TextFileTokens;
use Phpdup\Text\TextRunHarvester;
use Phpdup\Text\TextTokenizer;

/**
 * Item 7.1: the harvest contract — shared windows seed pairs, LCE
 * extension finds the maximal agreement, floors reject trivia, df-cap
 * skips boilerplate, per-file intervals merge, and the whole thing is
 * deterministic regardless of input order.
 */
final class TextRunHarvesterTest extends TestCase
{
    private function ft(string $name, string $src): TextFileTokens
    {
        $profile = LangProfiles::profileFor($name);
        $this->assertNotNull($profile);
        return new TextFileTokens('/tmp/' . $name, $profile, TextTokenizer::tokenize($src, $profile));
    }

    /** @return array<string, list<string>> file basename → 'start:end' run map */
    private function runMap(array $runs): array
    {
        $out = [];
        foreach ($runs as $r) {
            $out[basename($r->file())][] = $r->start . ':' . $r->end;
        }
        foreach ($out as &$list) {
            sort($list);
        }
        unset($list);
        ksort($out);
        return $out;
    }

    public function testBlankIdenticalTwinsHarvestBothSides(): void
    {
        $a = $this->ft('a.js', <<<'JS'
        alpha one( x1, 'lit1' );
        beta two( x2, 'lit2' );
        gamma three( x3, 'lit3' );
        delta four( x4, 'lit4' );
        JS);
        $b = $this->ft('b.js', <<<'JS'
        zeta one( y1, "lit9" );
        eta two( y2, "lit8" );
        theta three( y3, "lit7" );
        iota four( y4, "lit6" );
        JS);
        $this->assertSame($a->blanked(), $b->blanked(), 'the two sources blank to one stream');

        $runs = (new TextRunHarvester())->harvest([$a, $b]);
        $this->assertCount(2, $runs);
        $map = $this->runMap($runs);
        $this->assertSame(['0:' . count($a->blanked())], $map['a.js'] ?? null);
        $this->assertSame(['0:' . count($b->blanked())], $map['b.js'] ?? null);
    }

    public function testFloorsRejectShortRuns(): void
    {
        // 9 shared tokens (< MIN_RUN_TOKENS) over 1 line.
        $a = $this->ft('a.js', 'one two three four five six seven eight nine;');
        $b = $this->ft('b.js', 'ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen;');
        $this->assertSame([], (new TextRunHarvester())->harvest([$a, $b]));

        // Same token count on one line: token floor passes nowhere…
        // 30 shared tokens but only 1 line (< MIN_RUN_LINES): rejected on the line floor.
        $long = implode(' ', array_fill(0, 30, 'w'));
        $c = $this->ft('c.js', $long);
        $d = $this->ft('d.js', $long);
        $runs = (new TextRunHarvester())->harvest([$c, $d]);
        $this->assertSame([], $runs, 'a single-line agreement is not worth reporting');
    }

    public function testUnrelatedFilesProduceNoRuns(): void
    {
        $a = $this->ft('a.js', "alpha( one );\nbeta( two );\ngamma( three );\ndelta( four );");
        $b = $this->ft('b.js', "x = 1 + 2;\ny = 3 * 4;\nz = 'q';\nreturn x + y + z;");
        $this->assertSame([], (new TextRunHarvester())->harvest([$a, $b]));
    }

    public function testBoilerplateBeyondDfCapNeverSeeds(): void
    {
        $boiler = "if ( state == null ) { return defaults ; }\nif ( state == null ) { return defaults ; }";
        $files = [];
        for ($i = 0; $i <= TextRunHarvester::MAX_DF; $i++) {
            $files[] = $this->ft('b' . $i . '.js', $boiler . "\nuniq" . $i . " = N" . $i . ";");
        }
        $this->assertSame([], (new TextRunHarvester())->harvest($files), 'df > MAX_DF is boilerplate: skipped, not matched');
    }

    public function testOverlappingRunsFromDifferentPairsMergePerFile(): void
    {
        $s1Tokens = array_merge(
            ['(', 'N', '(', 'N', '(', 'N', '(', 'N', '(', 'N'],              // unique-ish opener
            array_fill(0, 30, 'I'),                                           // shared block S1
            array_fill(0, 30, '+'),                                           // shared block S2
            [')', 'N', ')', 'N'],
        );
        $s2Tokens = array_merge(array_fill(0, 30, 'I'), ['@', '@', '@']);
        $s3Tokens = array_merge(['#', '#'], array_fill(0, 30, '+'));

        // Build real sources whose BLANKED streams are the above: idents
        // blank to I, '+' / '@' / '#' stay punct, '(' ')' punct, N numbers.
        $mk = static function (array $blanks): string {
            $src = '';
            $i = 0;
            foreach ($blanks as $want) {
                $piece = match ($want) {
                    'I' => 'w' . $i,
                    'N' => (string) (100 + $i),
                    default => $want,
                };
                $src .= $piece . (($i % 8 === 7) ? "\n" : ' ');
                $i++;
            }
            return $src;
        };
        $a = $this->ft('a.js', $mk($s1Tokens));
        $b = $this->ft('b.js', $mk($s2Tokens));
        $c = $this->ft('c.js', $mk($s3Tokens));

        $runs = (new TextRunHarvester())->harvest([$a, $b, $c]);
        $map = $this->runMap($runs);
        $this->assertSame(['10:70'], $map['a.js'], 'the two pair-runs touch and merge into one maximal interval');
        $this->assertSame(['0:30'], $map['b.js']);
        $this->assertSame(['2:32'], $map['c.js']);
    }

    public function testHarvestIsOrderIndependentAndRepeatable(): void
    {
        $src = "alpha one( 'x1' );\nbeta two( 'x2' );\ngamma three( 'x3' );\ndelta four( 'x4' );";
        $alt = "zeta one( 'y1' );\neta two( 'y2' );\ntheta three( 'y3' );\niota four( 'y4' );";
        $a = $this->ft('a.js', $src);
        $b = $this->ft('b.js', $alt);

        $f1 = (new TextRunHarvester())->harvest([$a, $b]);
        $f2 = (new TextRunHarvester())->harvest([$b, $a]);
        $f3 = (new TextRunHarvester())->harvest([$a, $b]);
        $this->assertSame($this->runMap($f1), $this->runMap($f2), 'file order never moves the runs');
        $this->assertSame($this->runMap($f1), $this->runMap($f3), 'repeat harvest is byte-stable');
    }

    public function testSubWindowInputIsHonestlyEmpty(): void
    {
        $a = $this->ft('a.js', 'one two');
        $b = $this->ft('b.js', 'one two');
        $this->assertSame([], (new TextRunHarvester())->harvest([$a, $b]));
    }
}
