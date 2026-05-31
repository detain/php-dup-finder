<?php

declare(strict_types=1);

namespace App\TreeTraversal;

use App\Entity\TreeNode;
use Psr\Log\LoggerInterface;

interface TreeTraversalStrategyInterface
{
    public function traverse(TreeNode $root): array;
    public function find(TreeNode $root, mixed $value): ?TreeNode;
    public function calculateDepth(TreeNode $root): int;
}

abstract class AbstractTreeTraversal implements TreeTraversalStrategyInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    protected function logTraversal(int $nodeCount): void
    {
        $this->logger->debug('Tree traversal completed', [
            'node_count' => $nodeCount,
        ]);
    }
}

final class DepthFirstTraversal extends AbstractTreeTraversal
{
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

        $this->logTraversal(count($result));
        return $result;
    }

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

    public function calculateDepth(TreeNode $root): int
    {
        if ($root->getChildren() === []) {
            return 1;
        }

        $maxDepth = 1;
        $stack = [[$root, 1]];

        while (count($stack) > 0) {
            [$node, $depth] = array_pop($stack);

            foreach ($node->getChildren() as $child) {
                $maxDepth = max($maxDepth, $depth + 1);
                $stack[] = [$child, $depth + 1];
            }
        }

        return $maxDepth;
    }
}

final class BreadthFirstTraversal extends AbstractTreeTraversal
{
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

        $this->logTraversal(count($result));
        return $result;
    }

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
