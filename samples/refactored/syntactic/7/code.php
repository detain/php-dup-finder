<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * Generic shaped-node renderer. Instead of writing the same visitor scaffold
 * for every AST family we describe each node as a tuple of (label, children),
 * and a single generic renderer walks the structure.
 */
interface Shaped
{
    /** @return array{0:string, 1:array<int,Shaped>, 2:array<int,scalar>} */
    public function shape(): array;
}

final class ShapedRenderer
{
    public function render(Shaped $node): string
    {
        [$label, $children, $atoms] = $node->shape();

        $renderedKids = array_map(fn(Shaped $c) => $this->render($c), $children);
        $allParts = array_merge(
            array_map(static fn($a) => (string) $a, $atoms),
            $renderedKids,
        );

        return $allParts === []
            ? $label
            : sprintf('%s(%s)', $label, implode(', ', $allParts));
    }
}

/* Each former visitX method becomes a one-line shape():
 *
 *  // NumberLiteral
 *  public function shape(): array { return ['Number', [], [sprintf('%.2f', $this->value)]]; }
 *
 *  // BinaryOp
 *  public function shape(): array { return ['Binary', [$this->left, $this->right], [$this->op]]; }
 *
 *  // UnaryOp
 *  public function shape(): array { return ['Unary',  [$this->operand],            [$this->op]]; }
 */
