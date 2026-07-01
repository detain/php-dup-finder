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
     *
     * @return Node
     */
    private function replaceHolesWithParams(Cluster $cluster): Node
    {
        // Deep clone so we don't mutate the original generalizedAst.
        $root = Normalizer::deepClone($cluster->generalizedAst);

        if ($cluster->holePaths === []) {
            return $root;
        }

        // Build a map of pathKey => Hole for quick lookup.
        /** @var array<string, Hole> $pathKeyToHole */
        $pathKeyToHole = [];
        foreach ($cluster->holes as $hole) {
            if (isset($cluster->holePaths[$hole->placeholder])) {
                $pathKey = $this->pathKey($cluster->holePaths[$hole->placeholder]);
                $pathKeyToHole[$pathKey] = $hole;
            }
        }

        $generalizedAst = $cluster->generalizedAst;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($pathKeyToHole, $generalizedAst) extends NodeVisitorAbstract {
            /** @var array<string, Hole> */
            private array $pathKeyToHole;
            private Node $generalizedAst;

            /** @param array<string, Hole> $pathKeyToHole */
            public function __construct(array $pathKeyToHole, Node $generalizedAst)
            {
                $this->pathKeyToHole = $pathKeyToHole;
                $this->generalizedAst = $generalizedAst;
            }

            public function enterNode(Node $node): ?Node
            {
                $path = $this->findPathForNode($node);
                if ($path === null) {
                    return null;
                }
                $pathKey = $this->pathKey($path);
                if (isset($this->pathKeyToHole[$pathKey])) {
                    $hole = $this->pathKeyToHole[$pathKey];
                    return new Node\Expr\Variable($hole->suggestedName);
                }
                return null;
            }

            /** @return list<int|string>|null */
            private function findPathForNode(Node $targetNode): ?array
            {
                $targetId = spl_object_id($targetNode);
                $foundPath = null;
                $this->walkWithPath($this->generalizedAst, [], $targetId, $foundPath);
                return $foundPath;
            }

            /**
             * @param Node|null $node
             * @param list<int|string> $path
             * @param int $targetId
             * @param list<int|string>|null $foundPath
             */
            private function walkWithPath(?Node $node, array $path, int $targetId, ?array &$foundPath): void
            {
                if ($node === null || $foundPath !== null) {
                    return;
                }
                if (spl_object_id($node) === $targetId) {
                    $foundPath = $path;
                    return;
                }
                foreach ($node->getSubNodeNames() as $sub) {
                    $val = $node->$sub ?? null;
                    if ($val instanceof Node) {
                        $this->walkWithPath($val, [...$path, $sub], $targetId, $foundPath);
                    }
                }
            }

            /** @param list<int|string> $path */
            private function pathKey(array $path): string
            {
                return implode('/', array_map('strval', $path));
            }
        });

        $result = $traverser->traverse([$root]);
        return $result[0];
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
        // Extract parameter list from signature.
        $params = $this->extractParameters($cluster->signature ?? '');

        $lines = [
            '<?php',
            'declare(strict_types=1);',
            '',
            'namespace Refactored;',
            '',
            '/**',
            ' * Auto-generated abstraction for phpdup cluster ' . $cluster->id . '.',
            ' * Extracted via anti-unification — REVIEW BEFORE MERGE.',
            ' */',
            "function {$funcName}({$params}): mixed",
            '{',
            '    // Extracted from ' . count($cluster->members) . ' members.',
            '    // TODO: implement the function body.',
            '    //',
            '    // Original signature: ' . str_replace(["\n", "\r"], ' ', (string)($cluster->signature ?? '')),
            '}',
        ];

        return implode("\n", $lines) . "\n";
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
