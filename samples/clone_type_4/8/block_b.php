<?php

declare(strict_types=1);

namespace App\TreeTraversal;

use App\Entity\TreeNode;
use Psr\Log\LoggerInterface;

final class IterativeTreeTraversal
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Traverses tree using depth-first search (iterative approach).
     *
     * This implementation uses an explicit stack to simulate recursion,
     * maintaining state without call stack overhead.
     */
    public function traverse(TreeNode $root): array
    {
        $result = [];
        $stack = [$root];

        while (count($stack) > 0) {
            $node = array_pop($stack);

            foreach ($node->getChildren() as $child) {
                $stack[] = $child;
            }

            $result[] = $node->getValue();
        }

        $this->logger->debug('Tree traversal completed', [
            'node_count' => count($result),
        ]);

        return $result;
    }

    /**
     * Finds a node with specific value using iterative DFS.
     */
    public function find(TreeNode $root, mixed $value): ?TreeNode
    {
        $stack = [$root];

        while (count($stack) > 0) {
            $node = array_pop($stack);

            if ($node->getValue() === $value) {
                return $node;
            }

            foreach ($node->getChildren() as $child) {
                $stack[] = $child;
            }
        }

        return null;
    }

    /**
     * Calculates tree depth using iteration with level tracking.
     */
    public function calculateDepth(TreeNode $root): int
    {
        if ($root->getChildren() === []) {
            return 1;
        }

        $maxDepth = 1;
        $queue = [[$root, 1]];

        while (count($queue) > 0) {
            [$node, $depth] = array_shift($queue);

            foreach ($node->getChildren() as $child) {
                $maxDepth = max($maxDepth, $depth + 1);
                $queue[] = [$child, $depth + 1];
            }
        }

        return $maxDepth;
    }
}
