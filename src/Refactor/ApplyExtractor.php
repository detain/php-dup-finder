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
 * every read/write carries a real diagnostic. The transaction + rollback
 * over the write loop is half B (output-S44b).
 */
final class ApplyExtractor
{
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
     */
    public function apply(Cluster $cluster, string $outDir): void
    {
        foreach ($cluster->members as $member) {
            if ($member->kind === 'script') {
                throw ScriptKindApplyRefusal::forCluster($cluster, 'ApplyExtractor::apply()');
            }
        }
        if ($cluster->generalizedAst === null || $cluster->signature === null) {
            return;
        }
        if (count($cluster->members) < 2) {
            return;
        }
        if ($cluster->holePaths === []) {
            return;
        }

        // 1. Build modified AST with hole nodes replaced by Variable references.
        $modifiedAst = $this->replaceHolesWithParams($cluster);

        // 2. Extract function name from signature.
        $funcName = $this->extractFunctionName($cluster->signature);

        // 3. output-004 (half A): plan every member rewrite — group members
        //    by file, one read + one parse per file, node-identity
        //    collection of ALL the file's members from that single tree,
        //    one replacement pass, count-verify, one prettyPrint — BEFORE
        //    any filesystem touch. Any miss throws here and the cluster is
        //    refused with zero files written.
        $rewrites = $this->planRewrites($cluster, $funcName);

        // 4. Build and write the function file (loud: no @-swallow).
        $functionFile = $this->buildFunctionFile($funcName, $modifiedAst, $cluster);
        $refactoredDir = $outDir . '/Refactored';
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
        }
        $safeId = Slug::safe($cluster->id);
        $this->writeFileLoud($cluster, $refactoredDir . "/{$safeId}.php", $functionFile);

        // 5. Write each member's file exactly once — every sibling member of
        //    the file landed inside the single prettyPrinted tree planned
        //    above, so one write carries the whole cluster's share.
        foreach ($rewrites as $file => $code) {
            $this->writeFileLoud($cluster, $file, $code);
        }
    }

    /**
     * Group the cluster's members by file (first-appearance order) and plan
     * each file's full rewrite without touching the filesystem for writes.
     *
     * @return array<string, string> file path => rewritten file text
     */
    private function planRewrites(Cluster $cluster, string $funcName): array
    {
        /** @var array<string, list<Block>> $byFile */
        $byFile = [];
        foreach ($cluster->members as $member) {
            $byFile[$member->file][] = $member;
        }

        $rewrites = [];
        foreach ($byFile as $file => $members) {
            $rewrites[$file] = $this->planFileRewrite($cluster, $file, $members, $funcName);
        }
        return $rewrites;
    }

    /**
     * One read, one parse, one node-identity collection, one replacement
     * pass, one prettyPrint for a whole file — or a loud refusal naming the
     * member that could not be rewritten. The refusal arrives before the
     * caller has written anything, so "partial application" is structurally
     * excluded for every miss class (php-dup-finder-output-004).
     *
     * @param list<Block> $members
     */
    private function planFileRewrite(Cluster $cluster, string $file, array $members, string $funcName): string
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
        /** @var array<int, Node> $replacements spl_object_id(target) => replacement */
        $replacements = [];
        /** @var array<int, Block> $claimants spl_object_id(target) => first claimant */
        $claimants = [];
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
            $replacements[$targetId] = $this->buildCallReplacement($funcName, $cluster, $targetNode);
        }

        $newStmts = $this->replaceNodesInStmts($stmts, $replacements, $cluster, $file);
        return $this->printer->prettyPrintFile($newStmts);
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
     * loudly, naming the file and the cluster.
     */
    private function writeFileLoud(Cluster $cluster, string $path, string $content): void
    {
        error_clear_last();
        $written = @file_put_contents($path, $content);
        if ($written === false) {
            throw new \RuntimeException(sprintf(
                'ApplyExtractor: cluster %s — writing %s failed (%s).',
                $cluster->id,
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
        foreach (['file_get_contents', 'file_put_contents', 'mkdir'] as $op) {
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
            $this->setNodeAtPath($root, $path, new Node\Expr\Variable(CodeParam::bareName($hole->suggestedName, $hole->placeholder)));
        }

        return $root;
    }

    /**
     * Set the node at $path to $replacement by traversing from $root.
     * Stops at the parent of the target node so we can assign the replacement
     * to the parent's property/array-slot.
     *
     * @param Node|null $root
     * @param list<int|string> $path
     */
    private function setNodeAtPath(?Node $root, array $path, Node $replacement): void
    {
        if ($root === null || $path === []) {
            return;
        }

        // Navigate to the PARENT of the target node (stop at path[:-1])
        $current = $root;
        $i = 0;
        // Loop until we've processed all but the last key, or until we return early
        // because we detected we're at the target's parent
        while ($i < count($path) - 1) {
            $key = $path[$i];
            if (is_int($key)) {
                // Navigate into an array property: find the array and return item at $key
                $next = $this->getArrayChild($current, $key);
                if ($next === null) {
                    return;
                }
                $current = $next;
                $i++;
            } else {
                // Navigate into a Node property
                $prop = $current->$key ?? null;
                if (is_array($prop)) {
                    // Array property: next key must be an integer index into this array
                    if ($i + 1 >= count($path)) {
                        return;
                    }
                    $nextKey = $path[$i + 1];
                    if (!is_int($nextKey)) {
                        return;
                    }
                    if (!isset($prop[$nextKey]) || !$prop[$nextKey] instanceof Node) {
                        return;
                    }
                    $current = $prop[$nextKey];
                    $i += 2; // consumed both the array key and the index key
                    // After consuming array+index, check if we're at the target and should set directly
                    if ($i === count($path) - 1 && !is_int($path[$i] ?? null)) {
                        $current->{$path[$i]} = $replacement;
                        return;
                    }
                } elseif ($prop === null) {
                    return;
                } else {
                    // Navigate to the node first
                    $current = $prop;
                    // After navigating, check if next key is the final property to set
                    $nextIdx = $i + 1;
                    if ($nextIdx === count($path) - 1 && !is_int($path[$nextIdx] ?? null)) {
                        $current->{$path[$nextIdx]} = $replacement;
                        return;
                    }
                    $i++;
                }
            }
        }

        // Now $current is the parent, and we set path[-1] on it
        $lastKey = $path[count($path) - 1];
        if (is_int($lastKey)) {
            $this->setArrayChild($current, $lastKey, $replacement);
        } else {
            $current->$lastKey = $replacement;
        }
    }

    /**
     * Get the child node at $index in an array property of $node.
     * Iterates over all subnode names; the first array-valued property that
     * has at least $index+1 items wins (handles Function_, If_, etc. which
     * each have exactly one primary array subnode).
     */
    private function getArrayChild(Node $node, int $index): ?Node
    {
        foreach ($node->getSubNodeNames() as $prop) {
            $val = $node->$prop ?? null;
            if (is_array($val)) {
                $idx = 0;
                foreach ($val as $item) {
                    if ($idx === $index && $item instanceof Node) {
                        return $item;
                    }
                    $idx++;
                }
            }
        }
        return null;
    }

    /**
     * Set the child node at $index in an array property of $node to $replacement.
     */
    private function setArrayChild(Node $node, int $index, Node $replacement): void
    {
        foreach ($node->getSubNodeNames() as $prop) {
            $val = $node->$prop ?? null;
            if (is_array($val)) {
                $idx = 0;
                foreach ($val as $itemKey => $item) {
                    if ($idx === $index && $item instanceof Node) {
                        $node->$prop[$itemKey] = $replacement;
                        return;
                    }
                    $idx++;
                }
            }
        }
    }

    /** @param list<int|string> $path */
    private function pathKey(array $path): string
    {
        return implode('/', array_map('strval', $path));
    }

    private function extractFunctionName(string $signature): string
    {
        // signature is like "function notifyByThreshold(\n    mixed $threshold,\n): mixed"
        if (preg_match('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $signature, $m)) {
            return $m[1];
        }
        return 'extractedFunction';
    }

    private function buildFunctionFile(string $funcName, Node $modifiedAst, Cluster $cluster): string
    {
        $params = $this->extractParameters($cluster->signature ?? '');
        $memberCount = count($cluster->members);

        // Extract the statements from the modified AST to use as function body
        $bodyLines = $this->extractBodyLines($modifiedAst);

        $lines = [
            '<?php',
            'declare(strict_types=1);',
            '',
            'namespace Refactored;',
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
     * @return list<string> indented PHP code lines
     */
    private function extractBodyLines(Node $node): array
    {
        try {
            // If it's a function or method, use its statements
            if ($node instanceof Node\Stmt\Function_) {
                $stmts = $node->stmts;
            } elseif ($node instanceof Node\Stmt\ClassMethod) {
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
                return ['    // (unsupported node type: ' . AstSerializer::shortType($node) . ')'];
            }

            if ($stmts === []) {
                return ['    // (empty body)'];
            }

            $body = trim($this->printer->prettyPrint($stmts));
            if ($body === '') {
                return ['    // (empty body)'];
            }

            return explode("\n", $body);
        } catch (\Throwable) {
            return ['    // (body extraction failed)'];
        }
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

    /** @return Node */
    private function buildCallReplacement(string $funcName, Cluster $cluster, Node $targetNode): Node
    {
        // Build argument list from holes.
        $args = [];
        foreach ($cluster->holes as $hole) {
            if ($hole->kind === 'optional_block') {
                // Optional blocks become a literal false (default value).
                $args[] = new Node\Arg(new Node\Scalar\LNumber(0));
            } else {
                // Code-domain name (declaration, body rewrites and these
                // call-site args must agree — CodeParam owns the '$'-boundary).
                $args[] = new Node\Arg(new Node\Expr\Variable(CodeParam::bareName($hole->suggestedName, $hole->placeholder)));
            }
        }

        $call = new Node\Expr\FuncCall(
            new Node\Name('Refactored\\' . $funcName),
            $args,
        );

        // For function/method declarations, wrap in Return.
        if ($targetNode instanceof Node\Stmt\Function_ || $targetNode instanceof Node\Stmt\ClassMethod) {
            return new Node\Stmt\Return_($call);
        }

        return $call;
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
