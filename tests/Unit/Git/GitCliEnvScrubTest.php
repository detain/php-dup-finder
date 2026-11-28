<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Git;

use Phpdup\Git\GitCli;
use PHPUnit\Framework\TestCase;

/**
 * Item 4.2 r1-M1 — the GIT_* env-scrub contract.
 *
 * Every git subprocess GitCli spawns runs with an explicit env that has
 * the whole GIT_* namespace removed (PATH/HOME/locale stay). Without the
 * scrub, an inherited GIT_DIR / GIT_WORK_TREE / GIT_INDEX_FILE silently
 * retargets blame/log at ANOTHER repository — the answers would be
 * plausible but belong to no files under analysis, which is exactly the
 * posture the fail-open sidecar must never take.
 */
final class GitCliEnvScrubTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git binary unavailable');
        }
        $this->tmp = sys_get_temp_dir() . '/phpdup_git_scrub_' . uniqid('t', true);
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        putenv('GIT_DIR');
        putenv('GIT_WORK_TREE');
        $this->removeDir($this->tmp);
    }

    public function testInheritedGitDirCannotRetargetRepositoryDiscovery(): void
    {
        $repo    = $this->makeRepo('repo');
        $decoy   = $this->makeRepo('decoy');
        $decoyGit = $decoy . '/.git';

        // Poison the parent env the way a hostile wrapper would.
        putenv('GIT_DIR=' . $decoyGit);
        putenv('GIT_WORK_TREE=' . $decoy);

        $root = GitCli::findRepositoryRoot($repo);
        self::assertNotNull($root, 'a poisoned env must not make the real repo undiscoverable');
        self::assertSame(realpath($repo), $root, 'discovery must answer the -C repo, never the inherited GIT_DIR');

        $sha = GitCli::headSha($repo);
        self::assertIsString($sha);
        self::assertSame(1, preg_match('/^[0-9a-f]{40}$/', $sha), 'the real repo HEAD answers');

        // The decoy is a DIFFERENT commit lineage: prove the two repos would
        // have disagreed (same file, different content → different HEAD).
        $decoySha = GitCli::headSha($decoy);
        self::assertIsString($decoySha);
        self::assertNotSame($sha, $decoySha, 'the fixture pair must actually be distinguishable or the pin proves nothing');
    }

    public function testScrubbedSpawnKeepsNonGitEnvAlive(): void
    {
        $repo = $this->makeRepo('repo2');
        putenv('GIT_DIR=' . $repo . '/nope-not-a-repo');

        [$out, , $code] = GitCli::run(['git', '-C', $repo, 'rev-parse', '--git-dir']);
        self::assertSame(0, $code, 'a poisoned GIT_DIR must not break the scrubbed spawn');
        self::assertStringContainsString('.git', trim($out), 'git answers the -C repo\'s own git dir');

        // Env enumeration works here, so the scrub path ran; prove PATH
        // survived by asking the child for its own shell-visible binary.
        [$out2, , $code2] = GitCli::run(['git', '--version']);
        self::assertSame(0, $code2, 'git resolves — non-GIT env (PATH) is not scrubbed');
        self::assertStringContainsString('git version', $out2);
    }

    private function makeRepo(string $name): string
    {
        $dir = $this->tmp . '/' . $name;
        mkdir($dir, 0o777, true);
        [, , $code] = GitCli::run(['git', '-c', 'init.defaultBranch=main', '-C', $dir, 'init', '-q']);
        self::assertSame(0, $code);
        file_put_contents($dir . '/f.php', "<?php\n// " . $name . " content\n");
        // GitCli spawns scrub GIT_*, so commit identity rides repo-local config.
        [, , $code] = GitCli::run(['git', '-C', $dir, 'config', 'user.name', 'Scrub Test']);
        self::assertSame(0, $code);
        [, , $code] = GitCli::run(['git', '-C', $dir, 'config', 'user.email', 'scrub@t']);
        self::assertSame(0, $code);
        foreach ([['add', 'f.php'], ['commit', '-m', 'seed ' . $name]] as $argv) {
            [, , $code] = GitCli::run(array_merge(['git', '-C', $dir], $argv));
            self::assertSame(0, $code, 'commit ' . implode(' ', $argv));
        }
        clearstatcache();
        return realpath($dir);
    }

    private function gitAvailable(): bool
    {
        $proc = proc_open(['git', '--version'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return proc_close($proc) === 0;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
