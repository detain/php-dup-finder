<?php

declare(strict_types=1);

namespace App\TreeTraversal;

use App\Entity\TreeNode;
use Psr\Log\LoggerInterface;

final class RecursiveTreeTraversal
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Traverses tree using depth-first search (recursive approach).
     *
     * This implementation uses call stack recursion to traverse
     * all nodes, visiting children before siblings (post-order).
     */
    public function traverse(TreeNode $root): array
    {
        $result = [];

        $this->traverseNode($root, $result);

        $this->logger->debug('Tree traversal completed', [
            'node_count' => count($result),
        ]);

        return $result;
    }

    private function traverseNode(TreeNode $node, array &$result): void
    {
        foreach ($node->getChildren() as $child) {
            $this->traverseNode($child, $result);
        }

        $result[] = $node->getValue();
    }

    /**
     * Finds a node with specific value using recursive DFS.
     */
    public function find(TreeNode $root, mixed $value): ?TreeNode
    {
        if ($root->getValue() === $value) {
            return $root;
        }

        foreach ($root->getChildren() as $child) {
            $found = $this->find($child, $value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Calculates tree depth using recursion.
     */
    public function calculateDepth(TreeNode $root): int
    {
        if ($root->getChildren() === []) {
            return 1;
        }

        $maxChildDepth = 0;

        foreach ($root->getChildren() as $child) {
            $childDepth = $this->calculateDepth($child);
            $maxChildDepth = max($maxChildDepth, $childDepth);
        }

        return 1 + $maxChildDepth;
    }
}
