<?php
declare(strict_types=1);

namespace Phpdup\Ir;

/**
 * Pretty-prints an IR tree to a deterministic token stream.
 *
 * The output is consumed by {@see IrSimilarity} (Jaccard /
 * subtree-hash comparisons) and is intended to be human-readable
 * for debugging — the format trades verbosity for clarity:
 *
 *   - Each node emits `<kind>` followed by `:scalar` when present.
 *   - Composite nodes wrap children in matching `(` … `)` brackets.
 *   - Children appear in traversal order with whitespace separators
 *     that are stripped during n-gram fingerprinting.
 *
 * Two structurally identical IR trees produce byte-identical token
 * lists — that is the property the similarity scorer relies on.
 */
final class IrPrinter
{
    /** @return list<string> */
    public function tokens(IrNode $ir): array
    {
        $out = [];
        $this->walk($ir, $out);
        return $out;
    }

    public function pretty(IrNode $ir, int $indent = 0): string
    {
        $pad = str_repeat('  ', $indent);
        $head = $pad . $ir->kind();
        $scalar = $ir->scalar();
        if ($scalar !== null) {
            $head .= ':' . $scalar;
        }
        $children = $ir->children();
        if ($children === []) {
            return $head;
        }
        $lines = [$head . ' {'];
        foreach ($children as $c) {
            $lines[] = $this->pretty($c, $indent + 1);
        }
        $lines[] = $pad . '}';
        return implode("\n", $lines);
    }

    /** @param list<string> $out */
    private function walk(IrNode $ir, array &$out): void
    {
        $tag = $ir->kind();
        $scalar = $ir->scalar();
        if ($scalar !== null) {
            $tag .= ':' . $scalar;
        }
        $children = $ir->children();
        if ($children === []) {
            $out[] = $tag;
            return;
        }
        $out[] = $tag . '(';
        foreach ($children as $c) {
            $this->walk($c, $out);
        }
        $out[] = ')';
    }
}
