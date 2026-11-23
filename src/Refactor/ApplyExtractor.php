<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Reporting\Slug;
use Phpdup\Util\AstSerializer;

/**
 * Extracts a refactored function from a cluster and rewrites member call sites.
 *
 * For each cluster:
 *   1. Builds the function body by replacing hole positions in the generalized
 *      AST with parameter variables.
 *   2. Writes the function to {$outDir}/Refactored/{clusterId}.php
 *   3. For each file holding members: one read, one parse, every member of
 *      that file located by node identity in the SAME tree, all replacements
 *      applied in one traversal, one prettyPrint, one write.
 *
 * output-004 (half A) law: the whole cluster is PLANNED (read, parsed,
 * located, rewritten in memory, count-verified) before a single byte is
 * written. A member that cannot be located — missing file, unreadable file,
 * unparseable file, stale span, two members on one node — refuses the
 * cluster loudly with zero files touched, mirroring the item 1.3
 * script-refusal posture. The old loop re-read and re-parsed each member's
 * file AFTER writing the previous member's rewrite, so every member past
 * the first in a shared file searched its original line spans in reshaped
 * text and silently applied to nothing — silent partial application, the
 * exact failure this restructure kills. IO failures are never swallowed:
 * every read/write carries a real diagnostic.
 *
 * output-004 (half B): the write window is a TRANSACTION. Every path it
 * can change — the function artifact and each member file — is
 * snapshotted BEFORE its first byte, every landing is read back and
 * byte-compared against the plan, and any window failure (refused write,
 * landing that does not match, un-readable landing) restores the
 * snapshots byte-for-byte, removes artifacts and directories the window
 * itself created, and only then lets the loud failure leave. A cluster
 * whose rewrite the extractor cannot vouch for never exists half on
 * disk, and the returned member-file list — the RefactorPatchReporter's
 * Applied tally — contains exactly the files verified on disk.
 *
 * output-005: the holes ride that plan HONESTLY now. setNodeAtPath()
 * places a terminal (property, index) pair ON the slot its path names
 * instead of descending one level into the statement being replaced; it
 * is property-aware (the old name-blind first-array-subnode walk could
 * not tell TryCatch's `stmts` from its `catches`, nor For's `init` from
 * its `cond`); and every miss class — unknown property, stale index,
 * bare-int key, descent through null — throws PlacementMiss instead of
 * returning silently, so an unreplaced hole can no longer vanish into a
 * written body. A bare expression landing in a statement slot is wrapped
 * into a full Stmt\Expression (the probe body `if ($s) { $knob }` has no
 * terminator and fails `php -l`). Body extraction failures refuse the
 * cluster rather than printing comment stubs into production files, and
 * no planned buffer reaches the write window until it has re-parsed.
 *
 * output-003: the redirect the member gets is FULLY QUALIFIED —
 * `\Refactored\{fn}()` — never the qualified-relative `Refactored\{fn}`
 * the old node emitted, which PHP resolved against the CALLING file's
 * namespace (a namespaced member fatalled on <MemberNs>\Refactored\fn,
 * and the emitted hint already said `\Refactored\`, so hint and applied
 * bytes disagreed). One static builder, qualifiedCallName(), is the
 * single source both hint and call node pass through. And an extracted
 * abstraction required by nobody is dead weight: the write window now
 * also lands a deterministic bootstrap stub (BOOTSTRAP_FILE beside the
 * artifacts) that requires every generated abstraction file, so the
 * tree has a documented load story the rewritten members actually
 * resolve through.
 *
 * output-002 (S46): the member rewrite now KEEPS the declaration and
 * replaces only its BODY. A Function_/ClassMethod member is cloned
 * (name, parameters, return type, visibility all ride through), and its
 * statement list becomes the single delegating call — `return
 * \Refactored\{fn}(...)` normally, a bare `Stmt\Expression` when the
 * member declares `: void` (a void contract may not return the
 * abstraction's value). The old shape returned a bare `Stmt\Return_` to
 * stand in for the WHOLE declaration node, so replaceNodesInStmts
 * deleted it: class bodies gained an illegal top-level `return` (php
 * -l: unexpected token "return"), and a file-scope function's
 * include-time top-level return erased the function external callers
 * still addressed plus every declaration behind it in the same file —
 * delete-and-orphan, not extract-and-redirect. The dry-run hint always
 * said "replace body with: return \Refactored\fn(...)" (the
 * RefactorPatchReporter memberHint); the applied bytes now honour it.
 * A Closure/arrow member has no declaration to keep — the only node-
 * local rewrite for it swaps the callable value for the result of an
 * immediate call at the definition site — so such clusters are refused
 * up front, in the same loud zero-write posture as the script
 * refusal; a method whose declaration carries no body at all
 * (abstract/interface) is refused at collection, before any member
 * replacement is built.
 *
 * output-001 (S45): the delegating call now passes each member's OWN
 * observed expressions, in the argument order the emitted signature
 * declares. The first life of buildCallReplacement passed the hole
 * PARAMETER NAMES as call-site arguments — Variable('$threshold') at a
 * position where the member scope never defines it, so every rewritten
 * call site was live undefined-variable code. It also walked the holes
 * in creation order while SignatureBuilder reorders the parameter list
 * (required holes in hole order, optional_block knobs appended last),
 * quietly binding value to wrong parameter whenever creation order
 * differed from declaration order — silent data corruption. Optional
 * knobs emitted a literal `0`, an argument not even the deprecated
 * php-parser v5 scalar class stands behind honestly, and every member
 * received identical arguments because no member index reached the
 * builder. The call is now built from the canonical parameter order:
 * for each required hole, the re-parsed observedValues[member] row
 * (php-parser reads a bare name row back as a constant fetch, so such
 * rows bind as string literals — a fetch of a missing constant is a
 * fatal); for each optional hole,
 * `true`/`false` keyed on whether this member actually carried the
 * segment (Hole::MISSING, the S27 canonical absence row). A required
 * hole whose row for THIS member is missing, or does not re-parse to
 * one expression, refuses the cluster in the loud zero-write taxonomy
 * — an argument is never invented.
 *
 * output-S49b (php-dup-finder-output-006, joint landing with S49a): the
 * promoted shared formals reach the code. The planning walk became TWO
 * phases — read/parse/locate (and hydrate unloaded member ASTs from the
 * located declaration nodes, so promotion runs on loaded evidence at the
 * apply venue, never on null) across every member file first; then the
 * cluster-level promotion set, derived ONCE through the same
 * {@see SignatureBuilder::renderedHoleVars()} reserved set the signature
 * renderer used, so declared parameters and call-site arguments agree by
 * construction: artifact params = promoted + required holes + optional
 * knobs, call args = the member's own promoted locals (in scope — S46
 * keeps the declaration) + observed rows + knobs. Consequence disclosed:
 * cross-file refusal ORDER shifted — every locate-class diagnostic of
 * every file now precedes every replacement-build diagnostic (within one
 * file the old order is byte-identical).
 */
final class ApplyExtractor
{
    /**
     * The one home of the generated abstraction namespace. The artifact's
     * `namespace` line, the members' fully qualified call, the reporter's
     * hint, and the bootstrap stub's directory all derive from this.
     */
    public const ARTIFACT_NAMESPACE = 'Refactored';

    /**
     * Deterministic bootstrap stub the write window lands beside the
     * artifacts; requiring it loads every generated abstraction.
     */
    public const BOOTSTRAP_FILE = '_autoload.php';

    private readonly Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    /**
     * Extract the refactored function and rewrite member call sites.
     *
     * Item 1.3 law: a cluster carrying any 'script' member is refused
     * BEFORE any file is written — a synthetic fold has no parse-tree
     * position to rewrite and no call sites to redirect; silent partial
     * application is the failure class this prevents.
     *
     * output-004 (half B): on success every returned path was rewritten
     * AND read back byte-for-byte inside the write-window transaction.
     *
     * @return list<string> member file paths rewritten and verified
     */
    public function apply(Cluster $cluster, string $outDir): array
    {
        foreach ($cluster->members as $member) {
            if ($member->kind === 'script') {
                throw ScriptKindApplyRefusal::forCluster($cluster, 'ApplyExtractor::apply()');
            }
            // output-002 (S46): a Closure/arrow member has no declaration
            // to keep. The only node-local rewrite would replace the
            // callable ITSELF with an immediate call — running the
            // abstraction at definition time and binding its result where
            // a callable value lived (silent semantic change). Refused in
            // the same loud, zero-write posture as the script kind.
            if ($member->kind === 'closure' || $member->kind === 'arrow') {
                throw new \RuntimeException(sprintf(
                    'ApplyExtractor: refusing cluster %s — %s member %s has no declaration to delegate through; applying '
                    . 'would run the extracted function where the callable is defined and replace the callable with its '
                    . 'result. Wrap a named abstraction and point the callable at it by hand; no files were written.',
                    $cluster->id,
                    $member->kind,
                    self::describeMember($member),
                ));
            }
        }
        if ($cluster->generalizedAst === null || $cluster->signature === null) {
            return [];
        }
        if (count($cluster->members) < 2) {
            return [];
        }
        if ($cluster->holePaths === []) {
            return [];
        }

        // 1. Build modified AST with hole nodes replaced by Variable references.
        $modifiedAst = $this->replaceHolesWithParams($cluster);

        // 2. Extract function name from signature (output-003: the
        //    unparseable-signature fallback is now the cluster-scoped
        //    `f_{id}` the reporter's hint names too — one identity).
        $funcName = $this->extractFunctionName($cluster->signature, $cluster->id);

        // 3. output-004 (half A): plan every member rewrite — group members
        //    by file, one read + one parse per file, node-identity
        //    collection of ALL the file's members from that single tree,
        //    one replacement pass, count-verify, one prettyPrint — BEFORE
        //    any filesystem touch. Any miss throws here and the cluster is
        //    refused with zero files written.
        $planned = $this->planRewrites($cluster, $funcName);

        // 4. Build the function file and compose the window's full landing
        //    set (function artifact first, then the bootstrap stub, then
        //    each member file exactly once — every sibling member of a
        //    file landed inside the single prettyPrinted tree planned
        //    above). output-003: the stub is part of the window, so a
        //    failed apply rolls it back exactly like every other landing.
        $functionFile = $this->buildFunctionFile($funcName, $modifiedAst, $cluster);
        $refactoredDir = $outDir . '/' . self::ARTIFACT_NAMESPACE;
        $safeId = Slug::safe($cluster->id);
        $functionPath = $refactoredDir . "/{$safeId}.php";
        $bootstrapPath = $refactoredDir . '/' . self::BOOTSTRAP_FILE;
        if (array_key_exists($functionPath, $planned)) {
            // Snapshot/restore keys one path by one — a member file that
            // doubles as the artifact destination would silently overwrite
            // one snapshot with the other. Refuse before the window.
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — the function artifact path %s is also a member file path; no files were written.',
                $cluster->id,
                $functionPath,
            ));
        }
        if (array_key_exists($bootstrapPath, $planned)) {
            // Same one-path-one-snapshot law for the bootstrap stub.
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — the bootstrap stub path %s is also a member file path; no files were written.',
                $cluster->id,
                $bootstrapPath,
            ));
        }

        /** @var array<string, string> $landings path => planned bytes, window order */
        $landings = [
            $functionPath => $functionFile,
            $bootstrapPath => $this->buildBootstrapStub(),
        ];
        foreach ($planned as $file => $pair) {
            $landings[$file] = $pair['rewritten'];
        }

        // 4b. output-005: the plan is final — EVERY buffer the window would
        //     write must re-parse before the window may open. A generated
        //     file that php-parser itself refuses to read back is a broken
        //     abstraction, not a rewrite: refused HERE, before any
        //     snapshot, mkdir or first byte, so "no files were written"
        //     remains honest.
        $this->assertBuffersReparse($cluster, $landings, $bootstrapPath);

        // 5. output-004 (half B): open the write window as a TRANSACTION.
        //    The snapshots are the bytes the plan read (member files carry
        //    them — no second read) and the function artifact's on-disk
        //    prior state (false = the path did not exist). Directory
        //    creation runs BEFORE any write: a refused mkdir still leaves
        //    "no files were written" true (half-A posture), and $outCreated
        //    / $dirCreated mark the trees the window itself added.
        $outPreExisted = is_dir($outDir);
        $snapshot = $this->captureSnapshots($cluster, $planned, $functionPath, $bootstrapPath);
        $dirCreated = false;
        if (!is_dir($refactoredDir)) {
            // Sibling arms run error_clear_last() immediately before their
            // @-guarded call so describeLastFailure reports THIS attempt —
            // never a stale diagnostic from an earlier arm (fix r1 LOW-1).
            error_clear_last();
            if (!@mkdir($refactoredDir, 0o775, true) && !is_dir($refactoredDir)) {
                // The @ keeps the raw warning off the user's stream; the throw
                // carries it, so the failure is reported, never swallowed.
                throw new \RuntimeException(sprintf(
                    'ApplyExtractor: refusing cluster %s — could not create %s (%s); no files were written.',
                    $cluster->id,
                    $refactoredDir,
                    self::describeLastFailure($refactoredDir),
                ));
            }
            $dirCreated = true;
        }

        // 6. The window: write every landing once, then read every landing
        //    back and byte-compare it against the plan. A refused write,
        //    a landing that did not match, or a landing that cannot be
        //    re-read aborts the WHOLE window — the snapshots go back
        //    first, byte-for-byte, then the loud failure leaves. The tree
        //    never exists in a half-rewritten state (php-dup-finder-output-004).
        try {
            foreach ($landings as $path => $bytes) {
                $this->writeFileLoud($path, $bytes);
            }
            $this->verifyLandings($landings);
        } catch (\Throwable $failure) {
            throw $this->rollbackWriteWindow(
                $cluster,
                $snapshot,
                $outDir,
                $refactoredDir,
                !$outPreExisted,
                $dirCreated,
                $failure->getMessage(),
            );
        }

        // 7. Every landing verified byte-for-byte: return exactly the
        //    member files the window rewrote — the RefactorPatchReporter's
        //    Applied tally rides this list, so counted == verified.
        return array_keys($planned);
    }

    /**
     * The name of a generated abstraction INSIDE its artifact directory:
     * `Refactored\{fn}` — no leading backslash (the FullyQualified call
     * node and the printer add it back, byte-for-byte the hint's text).
     * output-003: the single builder both apply's call sites and the
     * reporter's member hints pass through.
     */
    public static function artifactName(string $funcName): string
    {
        return self::ARTIFACT_NAMESPACE . '\\' . $funcName;
    }

    /**
     * The resolved call text a rewritten member emits — `\Refactored\{fn}`
     * — identical to what the printer renders for the call node and to
     * the reporter's `replace body with:` hint.
     */
    public static function qualifiedCallName(string $funcName): string
    {
        return '\\' . self::artifactName($funcName);
    }

    /**
     * Path of the bootstrap stub a window opening on $outDir lands. Built
     * exactly like apply() builds it, so the reporter's summary names the
     * very file the transaction verified.
     */
    public static function bootstrapStubPath(string $outDir): string
    {
        return $outDir . '/' . self::ARTIFACT_NAMESPACE . '/' . self::BOOTSTRAP_FILE;
    }

    /**
     * The deterministic bootstrap stub (output-003). A generated
     * abstraction required by nothing is dead weight: PHP only resolves
     * the rewritten `\Refactored\fn()` calls once a file declaring them
     * is loaded. This stub requires every sibling abstraction file —
     * sorted glob, self-excluded, no side effects beyond the requires —
     * and carries NO path-dependent bytes, so re-applying the same
     * cluster lands the identical stub and the transaction stays
     * byte-stable across runs.
     */
    private function buildBootstrapStub(): string
    {
        $stub = self::BOOTSTRAP_FILE;
        $lines = [
            '<?php',
            'declare(strict_types=1);',
            '',
            '/**',
            ' * phpdup bootstrap stub for generated abstractions (output-003).',
            ' *',
            ' * Rewritten call sites invoke ' . self::qualifiedCallName('{fn}') . ' — fully',
            ' * qualified and namespace-independent — but those names resolve',
            ' * only once the files declaring them are LOADED. This stub',
            ' * requires every sibling abstraction file exactly once; load',
            " * individual files by hand instead if you prefer. Add",
            " *   require_once '<this directory>/" . $stub . "';",
            ' * to your entry point.',
            ' */',
            '',
            '$phpdupAbstractions = glob(__DIR__ . \'/*.php\');',
            'foreach ($phpdupAbstractions === false ? [] : $phpdupAbstractions as $phpdupFile) {',
            '    if ($phpdupFile === __FILE__ || !is_file($phpdupFile)) {',
            '        continue; // this stub, or a non-file glob match',
            '    }',
            '    require_once $phpdupFile;',
            '}',
            'unset($phpdupAbstractions, $phpdupFile);',
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * Parse every planned buffer and demand that it reads back.
     *
     * output-005: the extractor's promise to a user is that --apply emits
     * CODE, not text that merely looks like code. Placement and body
     * extraction each carry their own refusal above; this is the belt for
     * the composition as a whole — no landing reaches the write window
     * unless php-parser accepts it. Runs after planning and before the
     * window opens, so the refusal keeps the half-A posture: zero bytes
     * touched. The first landing is the generated function artifact, the
     * second the generated bootstrap stub (output-003); the rest are
     * member rewrites, named by path.
     *
     * @param array<string, string> $landings path => planned bytes
     * @throws \RuntimeException naming the first buffer that does not re-parse
     */
    private function assertBuffersReparse(Cluster $cluster, array $landings, string $bootstrapPath): void
    {
        $artifactPath = array_key_first($landings);
        foreach ($landings as $path => $bytes) {
            $parser = new AstParser();
            if ($parser->parseCode($bytes) !== null) {
                continue;
            }
            $subject = match (true) {
                $path === $artifactPath => 'the generated function file',
                $path === $bootstrapPath => 'the generated bootstrap stub',
                default => 'the rewrite of ' . $path,
            };
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — %s does not re-parse after planning (%s); no files were written.',
                $cluster->id,
                $subject,
                $parser->lastError?->getMessage() ?? 'unknown parse error',
            ));
        }
    }

    /**
     * Snapshot every path the write window is about to touch, BEFORE its
     * first byte. Member files carry the exact bytes the plan read (one
     * read per file, half-A law preserved); the function artifact's and
     * the bootstrap stub's prior states are read here (output-003: the
     * stub is a window landing like any other), and an existing-but
     * unreadable generated file refuses the cluster while nothing has
     * been written yet. The artifact check runs first — its refusal
     * message is a pinned diagnostic.
     *
     * @param array<string, array{original: string, rewritten: string}> $planned
     * @return array<string, string|false> path => pre-write bytes, false = absent
     */
    private function captureSnapshots(Cluster $cluster, array $planned, string $functionPath, string $bootstrapPath): array
    {
        $snapshot = [];
        if (is_file($functionPath)) {
            error_clear_last();
            $prior = @file_get_contents($functionPath);
            if ($prior === false) {
                throw new \RuntimeException(sprintf(
                    'ApplyExtractor: refusing cluster %s — the existing artifact %s could not be read (%s); no files were written.',
                    $cluster->id,
                    $functionPath,
                    self::describeLastFailure($functionPath),
                ));
            }
            $snapshot[$functionPath] = $prior;
        } else {
            $snapshot[$functionPath] = false;
        }
        if (is_file($bootstrapPath)) {
            error_clear_last();
            $prior = @file_get_contents($bootstrapPath);
            if ($prior === false) {
                throw new \RuntimeException(sprintf(
                    'ApplyExtractor: refusing cluster %s — the existing bootstrap stub %s could not be read (%s); no files were written.',
                    $cluster->id,
                    $bootstrapPath,
                    self::describeLastFailure($bootstrapPath),
                ));
            }
            $snapshot[$bootstrapPath] = $prior;
        } else {
            $snapshot[$bootstrapPath] = false;
        }
        foreach ($planned as $file => $pair) {
            $snapshot[$file] = $pair['original'];
        }
        return $snapshot;
    }

    /**
     * Read every written path back and demand byte equality with the plan.
     * This is what makes "Applied" a VERIFIED tally: a write the disk
     * swallowed, short-wrote, or raced against is caught here — inside the
     * window, while rollback can still undo it.
     *
     * @param array<string, string> $landings path => planned bytes
     */
    private function verifyLandings(array $landings): void
    {
        foreach ($landings as $path => $bytes) {
            error_clear_last();
            $onDisk = @file_get_contents($path);
            if ($onDisk === false) {
                throw new \RuntimeException(sprintf(
                    'the rewrite of %s could not be read back (%s)',
                    $path,
                    self::describeLastFailure($path),
                ));
            }
            if ($onDisk !== $bytes) {
                throw new \RuntimeException(sprintf(
                    'the rewrite of %s did not land byte-for-byte (%d bytes planned, %d on disk)',
                    $path,
                    strlen($bytes),
                    strlen($onDisk),
                ));
            }
        }
    }

    /**
     * Restore every snapshotted path to its pre-first-write state and turn
     * the window failure into the final loud refusal.
     *
     * Per path: absent-before ⇒ removed if still there; present-before ⇒
     * re-written when the current bytes diverge, then re-read to prove the
     * restore landed. Directories the window itself created are removed
     * when empty. A restore that itself fails is reported honestly as
     * ROLLBACK INCOMPLETE — the exception never claims a clean tree it
     * does not have.
     *
     * @param array<string, string|false> $snapshot
     */
    private function rollbackWriteWindow(
        Cluster $cluster,
        array $snapshot,
        string $outDir,
        string $refactoredDir,
        bool $outCreated,
        bool $dirCreated,
        string $cause
    ): \RuntimeException {
        $restored = 0;
        /** @var list<string> $problems */
        $problems = [];

        foreach ($snapshot as $path => $original) {
            if ($original === false) {
                if (!is_file($path)) {
                    continue;
                }
                error_clear_last();
                if (@unlink($path)) {
                    ++$restored;
                    continue;
                }
                clearstatcache(true, $path);
                if (is_file($path)) {
                    $problems[] = sprintf('%s could not be removed (%s)', $path, self::describeLastFailure($path));
                }
                continue;
            }

            error_clear_last();
            $current = @file_get_contents($path);
            if ($current === $original) {
                continue; // the window never changed this path
            }
            error_clear_last();
            if (@file_put_contents($path, $original) === false) {
                $problems[] = sprintf('%s could not be restored (%s)', $path, self::describeLastFailure($path));
                continue;
            }
            error_clear_last();
            if (@file_get_contents($path) !== $original) {
                $problems[] = sprintf('restoring %s did not land byte-for-byte (%s)', $path, self::describeLastFailure($path));
                continue;
            }
            ++$restored;
        }

        if ($dirCreated) {
            $problem = $this->removeOwnEmptyDir($refactoredDir);
            if ($problem !== null) {
                $problems[] = $problem;
            }
            if ($outCreated) {
                $problem = $this->removeOwnEmptyDir($outDir);
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
        }

        $outcome = $problems === []
            ? sprintf('write window rolled back, %d path(s) restored to their pre-write state.', $restored)
            : sprintf('ROLLBACK INCOMPLETE — %s; the tree may be PARTIALLY rewritten.', implode('; ', $problems));

        return new \RuntimeException(sprintf(
            'ApplyExtractor: refusing cluster %s — %s; %s',
            $cluster->id,
            $cause,
            $outcome,
        ));
    }

    /**
     * Remove a directory THIS window created, and only while it is empty —
     * a concurrent writer having filled it is a reason to keep it, not to
     * destroy foreign bytes. Returns null when there is nothing to report.
     */
    private function removeOwnEmptyDir(string $dir): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return sprintf('could not inspect the created directory %s (%s)', $dir, self::describeLastFailure($dir));
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return null; // not ours to take — leave it, report nothing
            }
        }
        error_clear_last();
        if (@rmdir($dir)) {
            return null;
        }
        // clearstatcache before any post-mutation existence re-check:
        // PHP's stat cache is stale by design after unlink/rmdir, and an
        // un-cleared is_dir here could re-report a directory that a
        // concurrent actor already removed.
        clearstatcache(true, $dir);
        return is_dir($dir)
            ? sprintf('the created directory %s could not be removed (%s)', $dir, self::describeLastFailure($dir))
            : null;
    }

    /**
     * Group the cluster's members by file (first-appearance order) and plan
     * each file's full rewrite without touching the filesystem for writes.
     *
     * output-S49b: the walk is TWO phases. Phase one reads, parses and
     * locates EVERY member in every file — all locate-class refusals
     * (missing file, unreadable, unparseable, stale span, double claim,
     * abstract body) therefore precede all replacement-build refusals
     * (missing observed row, unparseable row, member attribution). Phase
     * two derives the cluster's promoted shared formals ONCE and builds
     * the rewrites. The promoted set is a cluster-level fact and must be
     * computed after every member's declaration evidence exists — the
     * phase split is what lets it.
     *
     * @return array<string, array{original: string, rewritten: string}>
     *         file path => the bytes the plan read + the rewritten text
     */
    private function planRewrites(Cluster $cluster, string $funcName): array
    {
        /** @var array<string, list<Block>> $byFile */
        $byFile = [];
        foreach ($cluster->members as $member) {
            $byFile[$member->file][] = $member;
        }

        /** @var array<string, array{original: string, stmts: list<Node\Stmt>, located: list<array{0: Block, 1: Node}>}> $contexts */
        $contexts = [];
        foreach ($byFile as $file => $members) {
            $contexts[$file] = $this->locateFileMembers($cluster, $file, $members);
        }

        // The promotion set the artifact's signature carries, derived with
        // the SAME reserved-name projection SignatureBuilder used — one
        // shared helper, so renderer and apply door cannot disagree on
        // which names the holes claim (the S49a handoff's silent
        // double-binding class). Member ASTs are current: phase one
        // hydrated every unloaded one from its located declaration node.
        //
        // Residual disclosed (output-S49b fix1, review r1 M-2 — see the
        // ParamIntersection class docblock for the full statement): this
        // door binds promoted formals, holes and knobs and NOTHING else.
        // A shared formal the intersection law refuses (by-ref/variadic/
        // defaulted/attribute/flag/name-divergent) is still read FREE by
        // the template body whenever that body references it, and the
        // rewrite lands regardless — behavior-changing applied artifact
        // (r1's BYREF `&$acc` and VARIADIC `...$rest` probes). The
        // commit's own negative pin
        // testDivergingFormalNamesPromoteNothingAtTheCallSites asserts
        // arity + php -l only and never executes, so it does not cover
        // this class. The full cure — a door-level free-variable
        // soundness scan — is BANKED as a rider for the next door-owner
        // lane (CLOSEOUT-BANK), not this lane's scope.
        $promoted = ParamIntersection::promote(
            $cluster->members,
            SignatureBuilder::renderedHoleVars($cluster),
        );

        $rewrites = [];
        foreach ($contexts as $file => $context) {
            $rewrites[$file] = $this->buildFileRewrite($cluster, $file, $context, $funcName, $promoted);
        }
        return $rewrites;
    }

    /**
     * Phase one for one member file: the is_file/read/parse/locate leg of
     * the old planFileRewrite, verbatim diagnostics and all. Every member
     * of this file is located by node identity in this single parse; the
     * read bytes and the tree ride out with the context so phase two never
     * re-reads (the plan's one read serves both legs — output-004 half B).
     *
     * Hydration (output-S49b): when a member arrives with an unloaded AST —
     * the lazyAst/BlockAstLoader world, where RefactorStage::rebaseClusters
     * re-anchors content-bound members only up to the fork boundary — the
     * located node IS that member's declaration root in a fresh tree of the
     * very file about to be rewritten. Filling it (Block::$ast is
     * public-mutable by design, AGENTS.md) follows the loader idiom
     * narrower than a re-anchor: ParamIntersection then reads the same
     * parameters the written artifact will receive, instead of inventing
     * nothing from null and desyncing against a signature that promotes.
     *
     * @param list<Block> $members
     * @return array{original: string, stmts: list<Node\Stmt>, located: list<array{0: Block, 1: Node}>}
     */
    private function locateFileMembers(Cluster $cluster, string $file, array $members): array
    {
        // Silent return, old :332-334 — now a named refusal.
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — member file %s does not exist (%s); no files were written.',
                $cluster->id,
                $file,
                self::describeMember($members[0]),
            ));
        }

        // Silent return, old :337-339 — now a named refusal with the
        // suppressed diagnostic (the @ keeps the warning off the user's
        // stream; it rides the exception message instead).
        error_clear_last();
        $code = @file_get_contents($file);
        if ($code === false) {
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — member file %s could not be read (%s); no files were written.',
                $cluster->id,
                $file,
                self::describeLastFailure($file),
            ));
        }

        // Silent return, old :343-345 — now a named refusal carrying the
        // php-parser reason.
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        if ($stmts === null) {
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — member file %s could not be parsed (%s); no files were written.',
                $cluster->id,
                $file,
                $parser->lastError?->getMessage() ?? 'unknown parse error',
            ));
        }

        // Node-identity collection (old :349-351 silent miss — now named):
        // every member of this file is located in THIS single parse, before
        // any rewrite reshapes the text.
        /** @var array<int, Block> $claimants spl_object_id(target) => first claimant */
        $claimants = [];
        /** @var list<array{0: Block, 1: Node}> $located */
        $located = [];
        foreach ($members as $member) {
            $targetNode = $this->findNodeInStmts($stmts, $member);
            if ($targetNode === null) {
                throw new \RuntimeException(sprintf(
                    'ApplyExtractor: refusing cluster %s — no %s node at lines %d-%d in %s (member %s); '
                    . 'the extraction is stale against the file on disk. No files were written.',
                    $cluster->id,
                    $member->kind,
                    $member->range->start,
                    $member->range->end,
                    $file,
                    self::describeMember($member),
                ));
            }
            $targetId = spl_object_id($targetNode);
            if (isset($claimants[$targetId])) {
                throw new \RuntimeException(sprintf(
                    'ApplyExtractor: refusing cluster %s — two members address the same node at lines %d-%d in %s (%s and %s); '
                    . 'no files were written.',
                    $cluster->id,
                    $member->range->start,
                    $member->range->end,
                    $file,
                    self::describeMember($claimants[$targetId]),
                    self::describeMember($member),
                ));
            }
            $claimants[$targetId] = $member;
            // output-002 (S46): a ClassMethod whose declaration carries no
            // body (abstract, or an interface member) cannot receive a
            // delegating one — printing `abstract public function x() {
            // return ...; }` is text php -l refuses while php-parser
            // accepts it, so the re-parse guard could not catch the lie.
            // Refused at collection, before any replacement is built.
            if ($targetNode instanceof Node\Stmt\ClassMethod && $targetNode->stmts === null) {
                throw new \RuntimeException(sprintf(
                    'ApplyExtractor: refusing cluster %s — method member %s in %s is abstract or interface-typed; it has '
                    . 'no body to replace with a delegating one, so the cluster cannot be applied without breaking the '
                    . 'declaration; no files were written.',
                    $cluster->id,
                    self::describeMember($member),
                    $file,
                ));
            }
            if ($member->ast === null) {
                $member->ast = $targetNode;
            }
            $located[] = [$member, $targetNode];
        }

        return ['original' => $code, 'stmts' => $stmts, 'located' => $located];
    }

    /**
     * Phase two for one member file: build every member's replacement from
     * the located pairs, apply them by node identity in one traversal,
     * count-verify and prettyPrint once. The transactional posture is
     * unchanged from output-004 — a PlacementMiss or count mismatch still
     * refuses loudly with zero bytes written (the caller holds every file's
     * plan before its first landing).
     *
     * @param array{original: string, stmts: list<Node\Stmt>, located: list<array{0: Block, 1: Node}>} $context
     * @param list<PromotedParam> $promoted
     * @return array{original: string, rewritten: string}
     */
    private function buildFileRewrite(Cluster $cluster, string $file, array $context, string $funcName, array $promoted): array
    {
        /** @var array<int, Node> $replacements spl_object_id(target) => replacement */
        $replacements = [];
        foreach ($context['located'] as [$member, $targetNode]) {
            $replacements[spl_object_id($targetNode)] =
                $this->buildCallReplacement($funcName, $cluster, $member, $targetNode, $promoted);
        }

        $newStmts = $this->replaceNodesInStmts($context['stmts'], $replacements, $cluster, $file);
        return ['original' => $context['original'], 'rewritten' => $this->printer->prettyPrintFile($newStmts)];
    }

    /** Compact member label for diagnostics: kind, name (or class), span. */
    private static function describeMember(Block $member): string
    {
        return sprintf(
            '%s %s [%d-%d]',
            $member->kind,
            $member->name ?? $member->class ?? 'anonymous',
            $member->range->start,
            $member->range->end,
        );
    }

    /**
     * Write one artifact or throw with the suppressed diagnostic — the old
     * @file_put_contents swallow is gone: an IO failure aborts the apply
     * loudly, naming the file. The message is the CAUSE clause of the
     * transaction refusal built in apply() (half B), which prepends the
     * cluster id and the rollback outcome.
     */
    private function writeFileLoud(string $path, string $content): void
    {
        error_clear_last();
        $written = @file_put_contents($path, $content);
        if ($written === false) {
            throw new \RuntimeException(sprintf(
                'writing %s failed (%s)',
                $path,
                self::describeLastFailure($path),
            ));
        }
    }

    /**
     * Pull the diagnostic behind the most recent @-guarded call on $path.
     * error_clear_last() runs right before each guarded call, so
     * error_get_last() is fresh; the "<op>(<path>): " preamble PHP prepends
     * is trimmed because the surrounding message already names the path.
     */
    private static function describeLastFailure(string $path): string
    {
        $failure = error_get_last();
        $message = is_array($failure) ? $failure['message'] : '';
        foreach (['file_get_contents', 'file_put_contents', 'mkdir', 'unlink', 'rmdir', 'scandir'] as $op) {
            $preamble = sprintf('%s(%s): ', $op, $path);
            if (str_starts_with($message, $preamble)) {
                $message = substr($message, strlen($preamble));
                break;
            }
        }
        return $message !== '' ? $message : 'no diagnostic recorded';
    }

    /**
     * Replace nodes at hole paths with Variable nodes referencing the parameter name.
     */
    private function replaceHolesWithParams(Cluster $cluster): Node
    {
        $root = Normalizer::deepClone($cluster->generalizedAst);

        if ($cluster->holePaths === [] || $cluster->holes === []) {
            return $root;
        }

        // Build pathKey => Hole map.
        /** @var array<string, Hole> $pathKeyToHole */
        $pathKeyToHole = [];
        foreach ($cluster->holes as $hole) {
            if (isset($cluster->holePaths[$hole->placeholder])) {
                $pathKey = $this->pathKey($cluster->holePaths[$hole->placeholder]);
                $pathKeyToHole[$pathKey] = $hole;
            }
        }

        // For each hole path, navigate to that position in the cloned AST and replace.
        foreach ($pathKeyToHole as $pathKey => $hole) {
            $path = $cluster->holePaths[$hole->placeholder];
            try {
                $this->setNodeAtPath($root, $path, new Node\Expr\Variable(CodeParam::bareName($hole->suggestedName, $hole->placeholder)));
            } catch (PlacementMiss $miss) {
                // output-005: the old walker returned SILENTLY on every
                // miss class, so an unreplaced hole reached disk inside a
                // rewritten body — invisible partial application, the
                // exact family this kills. A hole path that does not
                // describe a slot is a contract violation between
                // AntiUnifier's walk and this template: refuse the cluster
                // loudly, before any file is even planned.
                throw new \RuntimeException(sprintf(
                    'ApplyExtractor: refusing cluster %s — %s; the hole paths and the generalized AST of this cluster disagree; no files were written.',
                    $cluster->id,
                    $miss->getMessage(),
                ));
            }
        }

        return $root;
    }

    /**
     * Set the node at $path to $replacement by traversing from $root.
     *
     * output-005 rewrite. The old walker consumed a (property, index) pair
     * greedily and then treated the element right after the pair as "parent
     * reached" — so the standard 2-segment hole path ['stmts', 0] descended
     * INTO the statement it was meant to replace and wrote the replacement
     * inside that statement's own first array subnode (or nowhere): one
     * level too deep, hole silently dropped. Every miss class — unknown
     * property, stale index, bare-int key, non-Node slot, descent through
     * null — simply RETURNED, so a body whose holes were never replaced
     * still reached disk.
     *
     * The contract, restated honestly (this IS the AntiUnifier contract —
     * semantics unchanged, now actually enforced): every segment is a
     * SUBNODE NAME of the node in hand, and an array property is followed
     * by its int index. Navigation stops at the node OWNING the terminal
     * slot: a terminal (property, index) pair assigns $owner->$prop[$index]
     * WITHOUT descending into the element being replaced. Any miss throws
     * PlacementMiss naming the path and the reason; no silent returns are
     * left in this method.
     *
     * Statement-slot honesty: when the slot holds a Node\Stmt and the
     * replacement is a bare Node\Expr (the optional-block hole is a
     * Variable), the replacement lands wrapped in Node\Stmt\Expression — a
     * bare expression printed into a statement list has no terminator (the
     * probe body `if ($s > 10) { $includeExtra }` failed `php -l`), which
     * the pre-write re-parse guard would otherwise refuse the whole cluster
     * for. That is simply how a statement slot must be written.
     *
     * @param Node $root the generalized AST clone the holes are placed into
     * @param list<int|string> $path hole path exactly as AntiUnifier produced it
     * @param Node $replacement the parameter Variable standing in for the hole
     * @throws PlacementMiss on every miss class
     */
    private function setNodeAtPath(Node $root, array $path, Node $replacement): void
    {
        $count = count($path);
        if ($count === 0) {
            throw new PlacementMiss(sprintf(
                'hole placement failed at <empty path>: a hole path must address at least one slot on %s',
                AstSerializer::shortType($root),
            ));
        }

        $owner = $root;
        $i = 0;

        // Walk every step EXCEPT the terminal slot: a string followed by an
        // int consumes both (descend $prop[$index]) — unless the pair ENDS
        // the path, in which case the pair is the terminal slot itself and
        // $owner stays right here (the old greedy descent is the bug).
        while ($i < $count - 1) {
            $segment = $path[$i];
            $this->assertSubnode($owner, $segment, $i, $path);
            $value = $owner->$segment;

            if ($i + 1 < $count && is_int($path[$i + 1])) {
                $index = $path[$i + 1];
                if (!is_array($value)) {
                    throw new PlacementMiss(sprintf(
                        'hole placement failed at %s: %s->%s is %s, not an array to index',
                        $this->pathKey($path),
                        AstSerializer::shortType($owner),
                        (string) $segment,
                        get_debug_type($value),
                    ));
                }
                if (!array_key_exists($index, $value)) {
                    throw new PlacementMiss(sprintf(
                        'hole placement failed at %s: %s->%s has no index %d',
                        $this->pathKey($path),
                        AstSerializer::shortType($owner),
                        (string) $segment,
                        $index,
                    ));
                }
                if (!$value[$index] instanceof Node) {
                    throw new PlacementMiss(sprintf(
                        'hole placement failed at %s: %s->%s[%d] holds %s, not a node',
                        $this->pathKey($path),
                        AstSerializer::shortType($owner),
                        (string) $segment,
                        $index,
                        get_debug_type($value[$index]),
                    ));
                }
                if ($i + 2 === $count) {
                    // Terminal pair: replace the SLOT ($prop[$index]),
                    // not the element currently in it.
                    $this->placeIntoSlot($owner, (string) $segment, $index, $value[$index], $replacement, $path);
                    return;
                }
                $owner = $value[$index];
                $i += 2;
                continue;
            }

            if (!$value instanceof Node) {
                throw new PlacementMiss(sprintf(
                    'hole placement failed at %s: cannot descend through %s->%s (%s is not a node)',
                    $this->pathKey($path),
                    AstSerializer::shortType($owner),
                    (string) $segment,
                    get_debug_type($value),
                ));
            }
            $owner = $value;
            $i++;
        }

        // Terminal: $owner OWNS the slot named by the last element.
        $terminal = $path[$count - 1];
        $this->assertSubnode($owner, $terminal, $count - 1, $path);
        $slot = $owner->$terminal;

        if ($slot instanceof Node || $slot === null) {
            // A null slot is still a slot the path legitimately addresses.
            $this->placeIntoSlot($owner, (string) $terminal, null, $slot, $replacement, $path);
            return;
        }

        if (is_array($slot)) {
            throw new PlacementMiss(sprintf(
                'hole placement failed at %s: "%s" addresses the whole array property on %s, not one of its slots — a hole path carries its index',
                $this->pathKey($path),
                (string) $terminal,
                AstSerializer::shortType($owner),
            ));
        }

        throw new PlacementMiss(sprintf(
            'hole placement failed at %s: %s->%s holds %s — a hole slot must hold a node or null',
            $this->pathKey($path),
            AstSerializer::shortType($owner),
            (string) $terminal,
            get_debug_type($slot),
        ));
    }

    /**
     * Every hole-path segment must name a subnode of the node in hand — a
     * bare array index addresses nothing. (The deleted getArrayChild /
     * setArrayChild pair was the opposite: name-BLIND, it picked the node's
     * FIRST array-valued subnode, so TryCatch's `stmts` and `catches` — or
     * For's `init` and `cond` — were indistinguishable to it. Property
     * names do the talking now.)
     *
     * @param list<int|string> $path
     * @throws PlacementMiss
     */
    private function assertSubnode(Node $node, int|string $segment, int $position, array $path): void
    {
        if (is_string($segment) && in_array($segment, $node->getSubNodeNames(), true)) {
            return;
        }
        throw new PlacementMiss(is_string($segment)
            ? sprintf(
                'hole placement failed at %s: %s has no subnode "%s"',
                $this->pathKey($path),
                AstSerializer::shortType($node),
                $segment,
            )
            : sprintf(
                'hole placement failed at %s: position %d is array index %d with no property name before it — hole paths address subnode properties by name',
                $this->pathKey($path),
                $position,
                $segment,
            ));
    }

    /**
     * Assign $replacement into $owner->$prop (one $index of an array
     * property when given) and READ THE SLOT BACK, demanding identity with
     * what was written — then the placement is a fact, not a hope. Typed
     * array properties (php-parser v5 If_::$stmts) reject wrong shapes
     * natively; every other surprise is caught here rather than reaching
     * the printer.
     *
     * @param list<int|string> $path
     * @throws PlacementMiss
     */
    private function placeIntoSlot(Node $owner, string $prop, ?int $index, mixed $slot, Node $replacement, array $path): void
    {
        $target = $replacement;
        if ($slot instanceof Node\Stmt && $replacement instanceof Node\Expr) {
            // Statement slot + bare expression: wrap or the printed body
            // lacks its terminator — see setNodeAtPath().
            $target = new Node\Stmt\Expression($replacement);
        }

        if ($index === null) {
            $owner->$prop = $target;
            $landed = $owner->$prop ?? null;
        } else {
            $owner->$prop[$index] = $target;
            $after = $owner->$prop ?? null;
            $landed = is_array($after) ? ($after[$index] ?? null) : null;
        }

        if (!$landed instanceof Node || $landed !== $target) {
            throw new PlacementMiss(sprintf(
                'hole placement failed at %s: assigning %s->%s%s did not land (the slot now holds %s)',
                $this->pathKey($path),
                AstSerializer::shortType($owner),
                $prop,
                $index === null ? '' : '[' . $index . ']',
                get_debug_type($landed),
            ));
        }
    }

    /** @param list<int|string> $path */
    private function pathKey(array $path): string
    {
        return implode('/', array_map('strval', $path));
    }

    /**
     * The name the extraction declares. output-003: one builder with the
     * reporter's hint — the name is FunctionNameRegistry::nameOf() when
     * the signature parses, and a CLUSTER-SCOPED fallback otherwise, so
     * an unparseable signature can never emit a call to a name that
     * silently collides across clusters (the old fixed
     * 'extractedFunction' was one such collision waiting to happen).
     */
    private function extractFunctionName(string $signature, string $clusterId = ''): string
    {
        // signature is like "function notifyByThreshold(\n    mixed $threshold,\n): mixed"
        return FunctionNameRegistry::nameOf($signature) ?? 'f_' . $clusterId;
    }

    private function buildFunctionFile(string $funcName, Node $modifiedAst, Cluster $cluster): string
    {
        $params = $this->extractParameters($cluster->signature ?? '');
        $memberCount = count($cluster->members);

        // Extract the statements from the modified AST to use as function
        // body. output-005: if extraction cannot produce a body, the
        // CLUSTER is refused before a byte of the plan exists. The old
        // behaviour was the loud kind of silence: catch everything, print
        // a body-extraction-failed comment stub AS THE BODY, and write a
        // production file claiming to be an abstraction it was not.
        try {
            $bodyLines = $this->extractBodyLines($modifiedAst);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — body extraction failed (%s); no files were written.',
                $cluster->id,
                $failure->getMessage(),
            ));
        }

        $lines = [
            '<?php',
            'declare(strict_types=1);',
            '',
            'namespace ' . self::ARTIFACT_NAMESPACE . ';',
            '',
            '/**',
            " * Auto-generated abstraction for phpdup cluster {$cluster->id}.",
            " * Extracted from {$memberCount} members via anti-unification — REVIEW BEFORE MERGE.",
            ' *',
            ' * Original signature: ' . str_replace(["\n", "\r"], ' ', (string)($cluster->signature ?? '')),
            ' */',
            "function {$funcName}({$params}): mixed",
            '{',
            ...$bodyLines,
            '}',
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * Extract body lines from a modified AST node (with holes replaced by variables).
     *
     * output-005 honesty: no try/catch, no comment stubs. A shape with no
     * function-body reading THROWS — buildFunctionFile turns that into the
     * loud cluster refusal. An empty body still prints `// (empty body)`:
     * that is legal output, not a failure class.
     *
     * @return list<string> indented PHP code lines
     * @throws \RuntimeException on a node shape that has no body reading
     */
    private function extractBodyLines(Node $node): array
    {
        // If it's a function or method, use its statements
        if ($node instanceof Node\Stmt\Function_) {
            $stmts = $node->stmts;
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            if ($node->stmts === null) {
                throw new \RuntimeException('ClassMethod has no body to extract (abstract or interface member)');
            }
            $stmts = $node->stmts;
        } elseif ($node instanceof Node\Stmt) {
            // It's a statement (if, for, foreach, while, do, try, switch, match, etc.)
            // Wrap it as the sole statement in the function body
            $stmts = [$node];
        } elseif ($node instanceof Node\Expr) {
            // It's an expression - wrap in return statement
            $exprCode = trim($this->printer->prettyPrintExpr($node));
            return ['    return ' . $exprCode . ';'];
        } else {
            throw new \RuntimeException('unsupported node type: ' . AstSerializer::shortType($node));
        }

        if ($stmts === []) {
            return ['    // (empty body)'];
        }

        $body = trim($this->printer->prettyPrint($stmts));
        if ($body === '') {
            return ['    // (empty body)'];
        }

        return explode("\n", $body);
    }

    private function extractParameters(string $signature): string
    {
        // Extract parameters from signature string like:
        // "function name(\n    int $threshold,\n    string $value,\n): mixed"
        if (preg_match('/\((.*)\)\s*:/s', $signature, $m)) {
            $params = trim($m[1]);
            // Remove leading/trailing whitespace and normalize internal whitespace
            $params = preg_replace('/\s+/', ' ', $params);
            return $params;
        }
        return '';
    }

    /**
     * @param list<Node\Stmt> $stmts
     */
    private function findNodeInStmts(array $stmts, Block $member): ?Node
    {
        $found = null;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($member, $found) extends NodeVisitorAbstract {
            public function __construct(private readonly Block $block, private ?Node &$found)
            {
            }

            #[\Override]
            public function enterNode(Node $node): ?Node
            {
                if ($this->found !== null) {
                    return null;
                }
                if ($node->getStartLine() !== $this->block->range->start) {
                    return null;
                }
                if ($node->getEndLine() !== $this->block->range->end) {
                    return null;
                }
                if (!self::matchesKind($node, $this->block->kind)) {
                    return null;
                }
                if ($this->block->name !== null) {
                    $name = match (true) {
                        $node instanceof Node\Stmt\Function_ => $node->name->toString(),
                        $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
                        default => null,
                    };
                    if ($name !== $this->block->name) {
                        return null;
                    }
                }
                $this->found = $node;
                return null;
            }

            private static function matchesKind(Node $node, string $kind): bool
            {
                return match ($kind) {
                    'function' => $node instanceof Node\Stmt\Function_,
                    'method' => $node instanceof Node\Stmt\ClassMethod,
                    'closure' => $node instanceof Node\Expr\Closure,
                    'arrow' => $node instanceof Node\Expr\ArrowFunction,
                    'if' => $node instanceof Node\Stmt\If_,
                    'for' => $node instanceof Node\Stmt\For_,
                    'foreach' => $node instanceof Node\Stmt\Foreach_,
                    'while' => $node instanceof Node\Stmt\While_,
                    'do' => $node instanceof Node\Stmt\Do_,
                    'try' => $node instanceof Node\Stmt\TryCatch,
                    'switch' => $node instanceof Node\Stmt\Switch_,
                    'match' => $node instanceof Node\Expr\Match_,
                    default => false,
                };
            }
        });
        $traverser->traverse($stmts);
        return $found;
    }

    /**
     * Build the node that takes a member's place: for Function_/ClassMethod
     * members a CLONE of the declaration whose body is the single delegating
     * call (output-002/S46), otherwise the bare call expression.
     *
     * output-001 (S45): the delegating call carries THIS member's own
     * observed expressions (buildCallArguments), in the order the emitted
     * signature declares its parameters — not hole-parameter names, not
     * hole-creation order, not one shared argument list for all members.
     *
     * @param list<PromotedParam> $promoted cluster-level shared formals
     *                                      (buildCallArguments prepends them)
     * @return Node
     */
    private function buildCallReplacement(string $funcName, Cluster $cluster, Block $member, Node $targetNode, array $promoted): Node
    {
        // Build the argument list for this member, in canonical order.
        $args = $this->buildCallArguments($cluster, $member, $promoted);

        $call = new Node\Expr\FuncCall(
            // output-003: FullyQualified, not the old qualified-relative
            // Name — a bare `Refactored\fn` written into a NAMESPACED
            // member resolved as <MemberNs>\Refactored\fn and fatalled at
            // runtime. The printer emits FullyQualified as
            // '\Refactored\fn', the exact text memberHint shows: hint and
            // applied call share one name builder (artifactName), so they
            // can no longer disagree.
            new Node\Name\FullyQualified(self::artifactName($funcName)),
            $args,
        );

        // output-002 (S46): declarations are KEPT, bodies delegate. The
        // old shape returned a bare Stmt\Return_ to replace the WHOLE
        // Function_/ClassMethod node — so a class body ended up with an
        // illegal top-level `return` (php -l: unexpected token "return")
        // and a file-scope function's include-time return erased the
        // declaration its external callers still addressed, plus every
        // declaration after it in the file. Cloning keeps name, params,
        // return type and visibility exactly as the member had them
        // (the dry-run hint's "replace body with: return \Refactored\
        // fn(...)" is now what actually lands); only the statement list
        // becomes the delegation. `: void` members get a bare
        // Stmt\Expression — returning the abstraction's value through a
        // void contract is a runtime TypeError class. S44a's atomicity
        // law survives: NodeAbstract's deep clone shares NO object with
        // the live tree, the discarded original body never rides into
        // the replacement, and any target the pass cannot reach is
        // still caught by replaceNodesInStmts' count-verify (the S44N
        // nested class refuses exactly as before).
        if ($targetNode instanceof Node\Stmt\Function_ || $targetNode instanceof Node\Stmt\ClassMethod) {
            $delegating = clone $targetNode;
            $delegating->stmts = self::returnsVoid($targetNode->returnType)
                ? [new Node\Stmt\Expression($call)]
                : [new Node\Stmt\Return_($call)];
            return $delegating;
        }

        // Non-declaration members (if/for/… regions) carry no signature to
        // keep — the bare call is the whole rewrite for them. Closure/arrow
        // kinds never reach here: apply() refuses them up front (S46).
        return $call;
    }

    /**
     * The delegating call's arguments for ONE member, in the order
     * SignatureBuilder declares the parameters: required holes in hole
     * order first, optional_block knobs appended last (output-001/S45 —
     * walking creation order as-is would bind values to wrong parameters
     * whenever a cluster's holes were not created required-first).
     *
     * Required holes pass the member's own observed expression, re-parsed
     * from its Hole::$observedValues row. Optional holes pass a `true`/
     * `false` constant keyed on whether THIS member actually carried the
     * segment — the row equal to Hole::MISSING (canonical absence, S27)
     * means "the member had nothing here", so the knob is switched off;
     * any real row means it did, so the knob is switched on. The knob
     * gates nothing inside the body until S49a's parameter promotion,
     * but its call value must be honest NOW: a bool parameter accepting a
     * literal 0 under strict_types is a TypeError class, and the old
     * literal rode on Node\Scalar\LNumber — a deprecated v5 alias.
     *
     * output-S49b: the promoted shared formals lead the argument list —
     * the same set SignatureBuilder rendered into the signature's leading
     * parameter lines, derived once per cluster in planRewrites with the
     * identical reserved-name projection. Each is bound to THIS member's
     * own local of that name (S46 keeps the member's declaration, so its
     * parameters are in scope at the rewritten call site). Params =
     * promoted + required holes + knobs; args = the same three families in
     * the same order — arity agrees by construction.
     *
     * @param list<PromotedParam> $promoted
     * @return list<Node\Arg>
     */
    private function buildCallArguments(Cluster $cluster, Block $member, array $promoted): array
    {
        $memberIdx = array_search($member, $cluster->members, true);
        if (false === $memberIdx) {
            // Defensive: planRewrites groups $cluster->members itself, so
            // no apply() path can arrive with an alien member. Fail fast and
            // loudly rather than guess an index — a wrong index would
            // silently bind another member's values.
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — call-site member %s is not one of the cluster members; the argument list cannot be attributed; no files were written.',
                $cluster->id,
                self::describeMember($member),
            ));
        }

        $args = [];
        foreach ($promoted as $param) {
            $args[] = new Node\Arg(new Node\Expr\Variable($param->name));
        }
        foreach ($cluster->holes as $hole) {
            if ('optional_block' !== $hole->kind) {
                $args[] = new Node\Arg($this->observedArgument($cluster, $hole, $memberIdx));
            }
        }
        foreach ($cluster->holes as $hole) {
            if ('optional_block' === $hole->kind) {
                $present = ($hole->observedValues[$memberIdx] ?? Hole::MISSING) !== Hole::MISSING;
                $args[] = new Node\Arg(new Node\Expr\ConstFetch(new Node\Name($present ? 'true' : 'false')));
            }
        }

        return $args;
    }

    /**
     * Re-parse one member's observed row into the expression the call
     * passes for a required hole (output-001/S45). The row is what
     * AntiUnifier saw at THIS member's position — passing it (instead of
     * the hole parameter's name) is what makes every call site define the
     * values it actually held. A row that is missing for this member, or
     * that does not read back as exactly one expression, refuses the
     * cluster in the loud zero-write taxonomy: an argument is never
     * invented, and a half-read row must not ride into a rewritten file.
     */
    private function observedArgument(Cluster $cluster, Hole $hole, int $memberIdx): Node\Expr
    {
        $row = $hole->observedValues[$memberIdx] ?? Hole::MISSING;
        $member = $cluster->members[$memberIdx];
        if (Hole::MISSING === $row) {
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — required hole %s has no observed value for member %s; the argument cannot be invented; no files were written.',
                $cluster->id,
                $hole->placeholder,
                self::describeMember($member),
            ));
        }

        // A row is either one statement (`audit('alpha', $x);' — what a
        // whole-statement hole prints, AntiUnifier's LCS granularity is the
        // statement) or one expression (what a sub-node row prints).
        // Normalise to a single statement, parse it, and take the expression
        // out: `return cleanup();`, `echo`, an `if` or two statements in a
        // row are NOT arguments and refuse honestly rather than smuggle an
        // unprintable fragment into a rewritten file.
        $text = trim($row);
        if (!str_ends_with($text, ';')) {
            $text .= ';';
        }

        $parser = new AstParser();
        $stmts = $parser->parseCode('<?php ' . $text);
        $why = null === $stmts
            ? ($parser->lastError?->getMessage() ?? 'unknown parse error')
            : '';

        $expr = (is_array($stmts) && 1 === count($stmts)
            && $stmts[0] instanceof Node\Stmt\Expression)
            ? $stmts[0]->expr
            : null;
        if (null === $expr) {
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — hole %s observed value for member %s does not read as a single expression (%s); no files were written.',
                $cluster->id,
                $hole->placeholder,
                self::describeMember($member),
                '' !== $why ? $why : 'not a single expression',
            ));
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            // php-parser reads a bare `Alpha\Thing` row back as a constant
            // fetch. true/false/null ARE constants and ride as fetched;
            // anything else is a name the member USED (a class, a registry
            // key) — passed as a fetch it would fatal at the call site on
            // the missing constant, so it binds as the string it printed
            // to, which is what the hole was gathered from.
            $name = $expr->name->toString();
            if (!in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                return new Node\Scalar\String_($name);
            }

            return $expr;
        }

        return $expr;
    }

    /** A `: void` declaration must delegate without returning a value. */
    private static function returnsVoid(?Node $returnType): bool
    {
        return $returnType instanceof Node\Identifier
            && 0 === strcasecmp($returnType->name, 'void');
    }

    /**
     * Replace ALL collected targets in one traversal, then count-verify
     * that every one of them actually landed. A target the pass failed to
     * visit (realistically: one member nested inside another member's
     * replaced subtree, which the traverser never re-enters) is a member
     * miss, not a silent skip — the exception fires before the caller
     * writes this file, and the cluster is refused.
     *
     * @param list<Node\Stmt> $stmts
     * @param array<int, Node> $replacements spl_object_id(target) => replacement
     * @return list<Node\Stmt>
     */
    private function replaceNodesInStmts(array $stmts, array $replacements, Cluster $cluster, string $file): array
    {
        $traverser = new NodeTraverser();

        $visitor = new class($replacements) extends NodeVisitorAbstract {
            public int $replaced = 0;

            /** @param array<int, Node> $replacements */
            public function __construct(private readonly array $replacements)
            {
            }

            #[\Override]
            public function enterNode(Node $node): ?Node
            {
                $id = spl_object_id($node);
                if (isset($this->replacements[$id])) {
                    ++$this->replaced;
                    return $this->replacements[$id];
                }
                return null;
            }
        };
        $traverser->addVisitor($visitor);

        /** @var list<Node\Stmt> $result */
        $result = $traverser->traverse($stmts);

        $expected = count($replacements);
        if ($visitor->replaced !== $expected) {
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: refusing cluster %s — only %d of %d collected member nodes in %s were rewritten '
                . '(a member nested under another member\'s replacement cannot be addressed); no files were written.',
                $cluster->id,
                $visitor->replaced,
                $expected,
                $file,
            ));
        }
        return $result;
    }
}

/**
 * A hole path that does not describe a slot of the generalized AST.
 *
 * output-005: the old setNodeAtPath() returned SILENTLY on every miss
 * class, so an unreplaced hole reached disk inside a rewritten body —
 * partial, invisible application, the exact failure family this kills.
 * PlacementMiss makes every miss loud at its source. It is produced only
 * inside ApplyExtractor::setNodeAtPath()/assertSubnode()/placeIntoSlot(),
 * where replaceHolesWithParams() turns it into the cluster refusal — so
 * it lives with its producer instead of claiming autoload surface it
 * never uses. Callers may catch \RuntimeException (which it extends) as
 * the apply-failure class it is.
 */
final class PlacementMiss extends \RuntimeException
{
}
