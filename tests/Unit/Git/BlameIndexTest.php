<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Git;

use Phpdup\Git\BlameIndex;
use PHPUnit\Framework\TestCase;

/**
 * Item 4.2 — the porcelain parser for `git blame --porcelain`.
 *
 * Pins the grammar law of the whole history leg: line->(sha,time,msg)
 * binds committer-time as the landing time (author-time is a valid but
 * weaker answer when committer metadata is absent), uncommitted lines
 * map to null, `previous` headers accumulate rename aliases, and every
 * stream that is not porcelain — or not complete porcelain — parses to
 * null so the analyzer reports 'unmeasurable' instead of guessing.
 */
final class BlameIndexTest extends TestCase
{
    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ZERO  = '0000000000000000000000000000000000000000';

    private function streamWithRename(): string
    {
        return implode("\n", [
            self::SHA_A . ' 1 1 4',
            'author Alice',
            'author-mail <alice@example.com>',
            'author-time 1700000000',
            'author-tz +0000',
            'committer Alice',
            'committer-mail <alice@example.com>',
            'committer-time 1700000100',
            'committer-tz +0000',
            'summary Initial import',
            'previous ' . self::SHA_B . ' lib/old-name.php',
            'boundary',
            "\t<?php",
            self::SHA_A . ' 2 2 4',
            "\t\$x = 1;",
            self::ZERO . ' 3 3 4',
            'author Not Committed Yet',
            'author-mail <uncommitted@example.com>',
            'author-time 1900000000',
            'committer-time 1900000000',
            'summary Not Committed Yet',
            "\t\$y = 2;",
            self::SHA_A . ' 4 4 4',
            "\t}",
            '',
        ]);
    }

    public function testParseLineMapAndCommitMetadata(): void
    {
        $index = BlameIndex::parse($this->streamWithRename());
        self::assertNotNull($index);

        self::assertSame(
            [1 => self::SHA_A, 2 => self::SHA_A, 3 => null, 4 => self::SHA_A],
            $index->lineSha,
            'committed lines map to their sha; the working-tree line maps to null',
        );
        $commit = $index->commit(self::SHA_A);
        self::assertIsArray($commit);
        self::assertSame('Alice', $commit['author']);
        self::assertSame(1700000100, $commit['time'], 'committer-time is the landing time and answers "last changed"');
        self::assertSame('Initial import', $commit['msg']);
        self::assertNull($index->commit(self::ZERO), 'the all-zero sha is not a commit');
        self::assertNull($index->commit(self::SHA_B), 'previous-only shas are not answerable commits');
        self::assertSame(['lib/old-name.php'], $index->previousPaths, 'rename aliases survive the parse');
    }

    public function testLastTouchIgnoresUncommittedLines(): void
    {
        $index = BlameIndex::parse($this->streamWithRename());
        self::assertNotNull($index);

        $touch = $index->lastTouch(2, 3);
        self::assertIsArray($touch);
        self::assertSame(self::SHA_A, $touch['sha'], 'a span straddling uncommitted lines still answers from its committed part');
        self::assertSame(1700000100, $touch['time']);

        self::assertNull($index->lastTouch(3, 3), 'an all-uncommitted span has no committed answer');
        self::assertNull($index->lastTouch(9, 9), 'a span outside the file answers honestly with null');
    }

    public function testCommitsInRangeDedupesAndSorts(): void
    {
        $index = BlameIndex::parse($this->streamWithRename());
        self::assertNotNull($index);

        self::assertSame([self::SHA_A], $index->commitsInRange(1, 4));
        self::assertSame([], $index->commitsInRange(3, 3));
        self::assertSame([], $index->commitsInRange(5, 6));
    }

    public function testCorruptStreamsParseToNull(): void
    {
        $corrupt = [
            'not blame output at all' => "hello\nworld\n",
            'content without header'  => "\tstray\n",
            'missing metadata'        => self::SHA_A . " 1 1 1\n\tcode\n",
            'metadata before header'  => "author Ghost\n" . self::SHA_A . " 1 1 1\n\tcode\n",
            'bad header shape'        => 'zzzz 1 1 1' . "\n" . "\tcode\n",
            'unknown grammar line'    => self::SHA_A . " 1 1 1\nauthor Alice\nsummary s\nmystery-key value\n\tcode\n",
            'empty output'            => '',
        ];
        foreach ($corrupt as $label => $stream) {
            self::assertNull(BlameIndex::parse($stream), $label . ' must parse to null, never a half-trust index');
        }
    }

    public function testFromPartsRebuildsDeterministically(): void
    {
        $index = BlameIndex::fromParts(
            [4 => self::SHA_A, 1 => self::SHA_A, 2 => null],
            [self::SHA_A => ['author' => 'A', 'time' => 1, 'msg' => 'm']],
            ['z.php', 'a.php'],
        );
        self::assertSame([1 => self::SHA_A, 2 => null, 4 => self::SHA_A], $index->lineSha, 'fromParts normalizes order');
        self::assertSame(['a.php', 'z.php'], $index->previousPaths);
        self::assertNull($index->lastTouch(2, 2));
    }

    public function testDanglingCommitReferenceRefusesToHalfAnswer(): void
    {
        $index = BlameIndex::fromParts(
            [1 => self::SHA_A],
            [],
            [],
        );
        self::assertNull($index->lastTouch(1, 1), 'a line whose sha has no metadata block must not fake a date');
        self::assertSame([], $index->commitsInRange(1, 1));
    }
}
