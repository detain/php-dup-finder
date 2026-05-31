<?php

declare(strict_types=1);

namespace App\TreeTraversal;

use App\Entity\TreeNode;
use Psr\Log\LoggerInterface;

final class LevelOrderTraversal
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Traverses tree using breadth-first search (level order).
     *
     * This implementation uses a queue to visit nodes level by level,
     * processing all nodes at depth d before depth d+1.
     */
    public function traverse(TreeNode $root): array
    {
        $result = [];
        $queue = [$root];

        while (count($queue) > 0) {
            $node = array_shift($queue);

            $result[] = $node->getValue();

            foreach ($node->getChildren() as $child) {
                $queue[] = $child;
            }
        }

        $this->logger->debug('Tree traversal completed', [
            'node_count' => count($result),
        ]);

        return $result;
    }

    /**
     * Finds a node with specific value using BFS.
     */
    public function find(TreeNode $root, mixed $value): ?TreeNode
    {
        $queue = [$root];

        while (count($queue) > 0) {
            $node = array_shift($queue);

            if ($node->getValue() === $value) {
                return $node;
            }

            foreach ($node->getChildren() as $child) {
                $queue[] = $child;
            }
        }

        return null;
    }

    /**
     * Calculates tree depth using level order traversal.
     */
    public function calculateDepth(TreeNode $root): int
    {
        if ($root->getChildren() === []) {
            return 1;
        }

        $depth = 0;
        $queue = [$root];

        while (count($queue) > 0) {
            $levelSize = count($queue);
            $depth++;

            for ($i = 0; $i < $levelSize; $i++) {
                $node = array_shift($queue);

                foreach ($node->getChildren() as $child) {
                    $queue[] = $child;
                }
            }
        }

        return $depth;
    }
}
