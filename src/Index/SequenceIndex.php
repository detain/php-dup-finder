<?php

declare(strict_types=1);

namespace Phpdup\Index;

/**
 * The ONE sequence store of the §5c shared-mining substrate (item 2.1).
 *
 * Shape of the contract (improve_logic_info.md §5c, frozen at 2.1):
 *  - exactly one per-file walker (BlockExtractor's statement-list collector)
 *    produces ordered statement lists;
 *  - exactly one wire row type carries them — PreprocessWorker's `mined`
 *    row, flavor `stmtseq`, scalar JSON payload (SOCKETPAIR law: rows are
 *    scalars only, never objects);
 *  - exactly this index consumes the decoded rows. Later census/idiom legs
 *    (items 3.1/3.2/3.5) attach NEW flavors to the same walker and rows;
 *    they must not require a second traversal — recordList() is additive
 *    per flavor by that construction.
 *
 * Stored per list: its content-derived id, the ordered statement-fold keys
 * ({@see \Phpdup\Util\StmtHash}), the line span, and the owner kind of the
 * enclosing declaration ('', 'function', 'method', 'closure', ...). The
 * occurrence map (fold key -> positions across the corpus) is the substrate
 * 3.1/3.5 read; v1 builds it lazily.
 *
 * Deliberately not part of any persisted cache: the index is rebuilt from
 * the row stream each run, so warm cache-reuse of per-file Block lists
 * never desynchronises it from suppression logic (which reads Block fields
 * only, never this index — same law, different reason).
 */
final class SequenceIndex
{
    /**
     * @var array<string, list<array{listId: string, hashes: list<string>, firstLine: int, lastLine: int, ownerKind: string}>>
     */
    private array $byFile = [];

    /**
     * @var array<string, list<array{file: string, listId: string, pos: int}>|null>
     */
    private ?array $occurrences = null;

    private int $lists = 0;

    private int $statements = 0;

    /**
     * Record one ordered statement-hash list for $file. Returns the
     * content-derived list id (stable across runs and engines).
     *
     * @param list<string> $hashes
     */
    public function recordList(string $file, array $hashes, int $firstLine, int $lastLine, string $ownerKind = ''): string
    {
        if ($hashes === []) {
            throw new \InvalidArgumentException('SequenceIndex: empty statement list for ' . $file);
        }

        $listId = self::deriveListId($file, $hashes, $firstLine, $lastLine);

        $this->byFile[$file][] = [
            'listId' => $listId,
            'hashes' => array_values($hashes),
            'firstLine' => $firstLine,
            'lastLine' => $lastLine,
            'ownerKind' => $ownerKind,
        ];
        $this->lists++;
        $this->statements += count($hashes);
        $this->occurrences = null;

        return $listId;
    }

    /**
     * @return list<array{listId: string, hashes: list<string>, firstLine: int, lastLine: int, ownerKind: string}>
     */
    public function listsFor(string $file): array
    {
        return $this->byFile[$file] ?? [];
    }

    /**
     * Corpus positions of every statement carrying $hash.
     *
     * @return list<array{file: string, listId: string, pos: int}>
     */
    public function positionsOf(string $hash): array
    {
        $this->buildOccurrences();

        return $this->occurrences[$hash] ?? [];
    }

    public function fileCount(): int
    {
        return count($this->byFile);
    }

    public function listCount(): int
    {
        return $this->lists;
    }

    public function statementCount(): int
    {
        return $this->statements;
    }

    /**
     * @param list<string> $hashes
     */
    private static function deriveListId(string $file, array $hashes, int $firstLine, int $lastLine): string
    {
        return sha1(implode(\Phpdup\Util\Delimiters::HASH_JOIN, [$file, (string) $firstLine, (string) $lastLine, (string) count($hashes), sha1(implode(\Phpdup\Util\Delimiters::HASH_JOIN, $hashes))]));
    }

    private function buildOccurrences(): void
    {
        if ($this->occurrences !== null) {
            return;
        }

        $map = [];
        foreach ($this->byFile as $file => $entries) {
            foreach ($entries as $entry) {
                foreach ($entry['hashes'] as $pos => $hash) {
                    $map[$hash][] = ['file' => $file, 'listId' => $entry['listId'], 'pos' => $pos];
                }
            }
        }
        /** @var array<string, list<array{file: string, listId: string, pos: int}>> $map */
        $this->occurrences = $map;
    }
}
