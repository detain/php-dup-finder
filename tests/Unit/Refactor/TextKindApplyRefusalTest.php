<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node\Stmt\InlineHTML;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Refactor\TextKindApplyRefusal;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\Report;
use Phpdup\Text\LangProfiles;
use Phpdup\Text\TextBlockFactory;
use Phpdup\Text\TextFileTokens;
use Phpdup\Text\TextRun;
use Phpdup\Text\TextTokenizer;
use Phpdup\Util\LineRange;

/**
 * Item 7.1: the token-tier apply refusal — a fifth family member in the
 * same voice as html_script/window/fragment/script, with the twist that
 * for 'text' even the loader half REFUSES (a text fold is not re-derivable:
 * its file is not PHP, and the ast/canonical pair is never legitimately
 * unloaded).
 */
final class TextKindApplyRefusalTest extends TestCase
{
    private function textBlock(string $file = 'a.js'): Block
    {
        $profile = LangProfiles::profileFor($file);
        $this->assertNotNull($profile);
        $tokens = new TextFileTokens('/tmp/' . $file, $profile, TextTokenizer::tokenize(
            "run( one, 'a' );\npost( two, 'b' );\nclear( three, 'c' );\ncheck( four, 'd' );\n",
            $profile,
        ));
        $run = new TextRun($tokens, 0, count($tokens->blanked()), 1, 4);
        return (new TextBlockFactory(new NgramFingerprint(), new SubtreeHasher()))->fromRun($run);
    }

    public function testApplyExtractorRefusesTextCluster(): void
    {
        $text = $this->textBlock();
        $ordinary = new Block('a.js', new LineRange(1, 2), 'function', null, null, 'x', new InlineHTML('I'));
        $cluster = new Cluster('C-t1', [$text, $ordinary], 1.0, false);
        $tmp = sys_get_temp_dir() . '/phpdup-tref-' . bin2hex(random_bytes(4));
        try {
            (new ApplyExtractor())->apply($cluster, $tmp . '/out');
            $this->fail('text clusters must be refused, never silently skipped');
        } catch (TextKindApplyRefusal $e) {
            $this->assertStringContainsString('ApplyExtractor', $e->getMessage());
            $this->assertStringContainsString("1 text-kind member", $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($tmp . '/out', 'refusal happens before any write');
    }

    public function testRefactorPatchReporterRefusesTheSurface(): void
    {
        $root = sys_get_temp_dir() . '/phpdup-trep-' . bin2hex(random_bytes(4));
        $report = new Report(
            files: 1, blocks: 1, parseErrors: 0,
            clusters: [new Cluster('C-t2', [$this->textBlock()], 1.0, false)],
            config: Config::defaults([$root]),
        );
        try {
            (new RefactorPatchReporter())->writeTo($report, $root . '/patches');
            $this->fail('patch surface must refuse lexical folds');
        } catch (TextKindApplyRefusal $e) {
            $this->assertStringContainsString('RefactorPatchReporter', $e->getMessage());
            $this->assertStringContainsString('C-t2', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root . '/patches');
    }

    public function testForBlockMessageCarriesTheNoReparseLaw(): void
    {
        $e = TextKindApplyRefusal::forBlock($this->textBlock(), 'TestSurface');
        $this->assertStringContainsString('--kinds text', $e->getMessage());
        $this->assertStringContainsString('no PHP parse to re-derive from', $e->getMessage());
    }

    public function testLoaderRefusesInsteadOfServing(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-tload-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700, true);
        $block = $this->textBlock($dir . '/a.js');
        $block->unloadAst();
        $loader = new BlockAstLoader(
            new AstCache($dir . '/ast'),
            new AstParser(),
            new Config(paths: [$dir], exclude: []),
        );
        try {
            $loader->resolve($block);
            $this->fail('the loader must refuse a text fold: it is not re-derivable');
        } catch (TextKindApplyRefusal $e) {
            $this->assertStringContainsString('BlockAstLoader', $e->getMessage());
        } finally {
            @rmdir($dir);
        }
    }

    public function testCatchUnionsCarryTheFifthFamily(): void
    {
        $command = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cli/Command.php');
        $watch = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Watch/WatchRunner.php');
        foreach (['Command catch union' => $command, 'WatchRunner runGuarded union' => $watch] as $label => $src) {
            $this->assertMatchesRegularExpression(
                '/catch \([^)]*TextKindApplyRefusal[^)]*\$e\)/',
                $src,
                $label . ' must include the 7.1 refusal',
            );
        }
    }
}
