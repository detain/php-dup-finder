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
 * Item 3.1 attaches its flavor WITHOUT touching any leg above: an
 * explicitly armed {@see \Phpdup\Census\SubtreeCensus} hung off this
 * instance (armIdioms()/idiomCensus()/recordIdiomFile()). Unarmed — the
 * default for every existing caller — these methods are inert or loudly
 * refusing, byFile/occurrences keep byte-identical behaviour, and no
 * subtree work is reachable. Demand gating, not lazy guessing: the
 * census only exists because a consumer armed it.
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

    /*
    |--------------------------------------------------------------------------
    | Item 3.1 attach point — idiom-subtree census (armed, additive)
    |--------------------------------------------------------------------------
    | The flavors() contract above promised: later legs attach to the same
    | walker and the same row stream. The idiom flavor's master-side store
    | is a SubtreeCensus, hosted HERE so the §5c "ONE shared substrate"
    | stays one: a consumer holding a SequenceIndex reaches statement
    | sequences AND the subtree census from the same object, and the later
    | census-output lane routes PreprocessStage's 'idiom' rows through
    | recordIdiomFile() beside the existing 'hashes' dispatch.
    */

    private ?\Phpdup\Census\SubtreeCensus $idiomCensus = null;

    /** Opt the instance into the idiom flavor (idempotent). */
    public function armIdioms(int $sampleCap = \Phpdup\Census\SubtreeCensus::DEFAULT_SAMPLE_CAP): void
    {
        $this->idiomCensus ??= new \Phpdup\Census\SubtreeCensus($sampleCap);
    }

    public function idiomsArmed(): bool
    {
        return $this->idiomCensus !== null;
    }

    /** The armed census, or null while the flavor is unarmed. */
    public function idiomCensus(): ?\Phpdup\Census\SubtreeCensus
    {
        return $this->idiomCensus;
    }

    /**
     * Ingest one file's decoded-subtree payload (the 'idiom' mined-row
     * JSON produced by SubtreeMiner::mineFile()). Refuses loudly when
     * the flavor was never armed — an unarmed index receiving idiom
     * rows is a wiring bug, not a demand signal (Fail Fast law).
     */
    public function recordIdiomFile(string $file, string $payloadJson): void
    {
        if ($this->idiomCensus === null) {
            throw new \LogicException('SequenceIndex: idiom payload for ' . $file . ' while idioms unarmed — arm() the flavor first');
        }
        $this->idiomCensus->recordFileJson($file, $payloadJson);
    }

    /*
    |--------------------------------------------------------------------------
    | Item 3.2 attach point — helper-adoption summary (armed, additive)
    |--------------------------------------------------------------------------
    | Same doctrine as the 3.1 block above: demand gating, nothing stored
    | per file, no new wire flavor. Adoption is a MASTER-SIDE derivation
    | over the two structures this index already holds (statement-list
    | spans + the idiom census) once Blocks exist, so PreprocessWorker
    | sends nothing extra — the §5c "ONE shared miner" law is honoured by
    | re-asking the census, never by re-walking trees. Arm without
    | idioms is a wiring bug (the summary reads the census): HelperAdoption
    | refuses; the stage always arms both together.
    */

    private bool $helpersArmed = false;

    private ?\Phpdup\Census\HelperAdoption $helperAdoption = null;

    /** Opt the instance into the helper-adoption summary (idempotent). */
    public function armHelpers(): void
    {
        $this->helpersArmed = true;
    }

    public function helpersArmed(): bool
    {
        return $this->helpersArmed;
    }

    /** The built summary, or null while unarmed / not yet built. */
    public function helperAdoption(): ?\Phpdup\Census\HelperAdoption
    {
        return $this->helperAdoption;
    }

    /**
     * Derive the summary from the pipeline's Block list. Refuses loudly
     * when the flavor was never armed (Fail Fast law, same posture as
     * recordIdiomFile()). Re-running overwrites with a byte-identical
     * result for identical inputs (pure derivation).
     *
     * @param list<\Phpdup\Extraction\Block> $blocks
     */
    public function buildHelpers(array $blocks): \Phpdup\Census\HelperAdoption
    {
        if (!$this->helpersArmed) {
            throw new \LogicException('SequenceIndex: helper adoption requested while unarmed — armHelpers() first');
        }
        $this->helperAdoption = \Phpdup\Census\HelperAdoption::analyze($this, $blocks);
        return $this->helperAdoption;
    }
}
