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
use Phpdup\Util\AstSerializer;

/**
 * Extracts a refactored function from a cluster and rewrites member call sites.
 *
 * For each cluster:
 *   1. Builds the function body by replacing hole positions in the generalized
 *      AST with parameter variables.
 *   2. Writes the function to {$outDir}/Refactored/{clusterId}.php
 *   3. For each member: parses their file, finds the block, replaces it with
 *      a call to the new function, and writes the file back.
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
     */
    public function apply(Cluster $cluster, string $outDir): void
    {
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

        // 3. Build and write the function file.
        $functionFile = $this->buildFunctionFile($funcName, $modifiedAst, $cluster);
        $refactoredDir = $outDir . '/Refactored';
        if (!is_dir($refactoredDir)) {
            @mkdir($refactoredDir, 0o775, true);
        }
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cluster->id);
        file_put_contents($refactoredDir . "/{$safeId}.php", $functionFile);

        // 4. Rewrite each member's call site.
        foreach ($cluster->members as $member) {
            $this->rewriteMemberCallSite($member, $funcName, $cluster);
        }
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
            $this->setNodeAtPath($root, $path, new Node\Expr\Variable($hole->suggestedName));
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
     * Rewrite a member's call site: replace the block with a function call.
     */
    private function rewriteMemberCallSite(Block $member, string $funcName, Cluster $cluster): void
    {
        if (!is_file($member->file)) {
            return;
        }

        $code = @file_get_contents($member->file);
        if ($code === false) {
            return;
        }

        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        if ($stmts === null) {
            return;
        }

        // Find the node matching this block.
        $targetNode = $this->findNodeInStmts($stmts, $member);
        if ($targetNode === null) {
            return;
        }

        // Build the replacement call.
        $replacement = $this->buildCallReplacement($funcName, $cluster, $targetNode);

        // Replace in the statements list.
        $newStmts = $this->replaceNodeInStmts($stmts, $targetNode, $replacement);

        // Pretty-print and write back.
        $newCode = $this->printer->prettyPrintFile($newStmts);
        @file_put_contents($member->file, $newCode);
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
                // Use the suggested name as a variable.
                $args[] = new Node\Arg(new Node\Expr\Variable($hole->suggestedName));
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
     * Replace $target with $replacement in the statement list.
     *
     * @param list<Node\Stmt> $stmts
     * @return list<Node\Stmt>
     */
    private function replaceNodeInStmts(array $stmts, Node $target, Node $replacement): array
    {
        $traverser = new NodeTraverser();
        $targetId = spl_object_id($target);

        $traverser->addVisitor(new class($targetId, $replacement) extends NodeVisitorAbstract {
            private bool $replaced = false;

            public function __construct(private readonly int $targetId, private readonly Node $replacement)
            {
            }

            public function enterNode(Node $node): ?Node
            {
                if ($this->replaced) {
                    return null;
                }
                if (spl_object_id($node) === $this->targetId) {
                    $this->replaced = true;
                    return $this->replacement;
                }
                return null;
            }
        });

        /** @var list<Node\Stmt> $result */
        $result = $traverser->traverse($stmts);
        return $result;
    }
}
