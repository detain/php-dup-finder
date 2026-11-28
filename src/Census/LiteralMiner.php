<?php
declare(strict_types=1);

namespace Phpdup\Census;

use PhpParser\Node;
use Phpdup\Normalization\SqlTableExtractor;

/**
 * Literal/constant census miner (item 3.3): the doc's five buckets,
 * read ONCE per file from the RAW parsed tree — the same in-memory
 * $stmts the extractor already produced (no second parse, no second
 * file walk; the ONE-walker law §5c keeps). The census must see raw
 * literals because every other normalization leg deliberately erases
 * them (canonicalizeLiterals turns strings into `__STR`); repeated
 * literals are precisely what those legs hide.
 *
 * Buckets (per the item letter):
 *  - 'string':   "string literals over a length threshold ... repeated
 *                somewhere else" — String_ (heredoc/nowdoc included;
 *                InlineHtml is markup, not a literal) at
 *                {@see STRING_MIN_LEN} bytes or more, grouped by the
 *                value itself.
 *  - 'array':    "array literals with three or more entries ... hashed
 *                order-insensitively" — Array_ with >= MIN_ARRAY_ITEMS
 *                items; the entry signatures sort before hashing, so
 *                ['a'=>1,'b'=>2] and ['b'=>2,'a'=>1] share one key
 *                (status maps and option tables are the archetype).
 *                Nested arrays fold into their parent's signature.
 *  - 'number':   "numeric constants repeated in comparable positions"
 *                — Int_/Float_ grouped by (value, parent shape) so the
 *                doc's "86400 on 112 lines" lands in ONE row per
 *                position-kind, not one per call syntax. Trivial 0/1/-1
 *                and array-item members (the 'array' bucket owns those)
 *                are skipped.
 *  - 'skeleton': "interpolated strings grouped by their literal
 *                skeleton" — Scalar\InterpolatedString with the
 *                expression parts collapsed to '?'. Mechanism note
 *                (r1 n-2): the skeleton is hand-built here on purpose
 *                — AstSerializer::scalarPart() (the §3.3-design cite)
 *                produces a framed serialization id, not a literal
 *                skeleton; the item letter's grouping needs the raw
 *                fragments verbatim. When
 *                SqlTableExtractor recognises a (verb, table) the row
 *                GROUPS BY THAT SHAPE instead: all statements touching
 *                one table form one repeated SQL shape (bullet 4's
 *                "the table names SqlTableExtractor gives for
 *                grouping"). Concat-built SQL is NOT captured
 *                (disclosed follow-on; the letter names interpolated
 *                strings).
 *  - 'const':    class constants and enum cases — declarations
 *                (Stmt\ClassConst / Stmt\EnumCase, roles 'decl' and
 *                'enum_case'), constant FETCHES (Expr\ClassConstFetch,
 *                role 'use'), grouped across classes by NAME alone —
 *                self::, static::, Qual:: spellings all join one row,
 *                and five classes each declaring STATUS_MAP are the
 *                doc's "the same magic-value map copied into five
 *                classes" story told in one row.
 *
 * Wire (scalar-only, SOCKETPAIR law): a list of per-file pre-
 * aggregated rows
 *   [kind, hash, sample, role, count, lines<=ROW_CAP]
 * where `hash` is the bucket-local group key (sha1 over the bucket
 * identity) and `role` is only non-empty for the 'const' bucket — the
 * accumulator keys include the role, so one file's STATUS_MAP emits a
 * ('const', NAME-hash, 'decl') row and a ('const', NAME-hash, 'use')
 * row; the master census merges roles across files.
 * Per-file row count caps at MAX_ROWS_PER_FILE (combined key-asc,
 * tail dropped, disclosed).
 */
final class LiteralMiner
{
    /** Shortest string literal censused (the "length threshold"). */
    public const STRING_MIN_LEN = 12;

    /** Array literals at this size or larger join the bucket. */
    public const MIN_ARRAY_ITEMS = 3;

    /** Sample lines sealed per (file, entry, role) row. */
    public const ROW_CAP = 4;

    /** Distinct rows emitted per file (key-asc tail drop). */
    public const MAX_ROWS_PER_FILE = 512;

    /** Display clip for the human sample. */
    public const SAMPLE_MAX_BYTES = 120;

    /**
     * Mine one file from its already-parsed raw statements.
     *
     * @param list<Node\Stmt> $stmts
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: list<int>}>
     */
    public function mineFile(array $stmts): array
    {
        /** @var array<string, array{kind:string,hash:string,sample:string,role:string,count:int,lines:list<int>}> $acc */
        $acc = [];
        $this->walk($stmts, $acc);
        if ($acc === []) {
            return [];
        }
        ksort($acc); // deterministic under any walk order (kind.hash.role)
        $order = array_keys($acc);
        $rows  = [];
        foreach (array_slice($order, 0, self::MAX_ROWS_PER_FILE) as $key) {
            $e      = $acc[$key];
            $rows[] = [$e['kind'], $e['hash'], $e['sample'], $e['role'], $e['count'], $e['lines']];
        }
        return $rows;
    }

    /**
     * Iterative (stack) walk: visit order cannot affect the
     * aggregation (counters + first ROW_CAP lines), and a stack keeps
     * deep trees off the PHP call stack. The 'const' bucket groups by
     * NAME alone (cross-class copies are the story), so no class
     * context is carried — the reading class lives in the row locations.
     *
     * @param list<Node> $nodes
     * @param array<string, array{kind:string,hash:string,sample:string,role:string,count:int,lines:list<int>}> $acc
     */
    private function walk(array $nodes, array &$acc): void
    {
        $stack = [];
        foreach (array_reverse($nodes) as $node) {
            if ($node instanceof Node) {
                $stack[] = [$node, null];
            }
        }
        while ($stack !== []) {
            [$node, $par] = array_pop($stack);
            $this->observe($node, $par, $acc);
            foreach ($node->getSubNodeNames() as $slot) {
                $child = $node->{$slot};
                if ($child instanceof Node) {
                    $stack[] = [$child, $node];
                } elseif (is_array($child)) {
                    foreach (array_reverse($child) as $item) {
                        if ($item instanceof Node) {
                            $stack[] = [$item, $node];
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array{kind:string,hash:string,sample:string,role:string,count:int,lines:list<int>}> $acc
     */
    private function observe(Node $node, ?Node $parent, array &$acc): void
    {
        $line = max(1, $node->getStartLine());

        if ($node instanceof Node\Scalar\InterpolatedString) {
            // Checked before String_: the two are siblings under
            // Node\Scalar, but ordering makes the intent obvious.
            $skeleton = self::interpolationSkeleton($node);
            $sql      = SqlTableExtractor::extract($skeleton);
            if ($sql !== null) {
                // The letter groups SQL by TABLE: every recognized
                // statement touching (verb, table) shares one row —
                // 'select id from invoices where …' and
                // 'select sum(total) from invoices where …' are one
                // repeated SQL shape. Non-SQL interpolations keep the
                // skeleton as their identity.
                $hash     = 'sql' . "\1" . strtoupper($sql[0]) . "\1" . strtolower($sql[1]);
                $skeleton = strtoupper($sql[0]) . ' ' . strtolower($sql[1]);
            } else {
                $hash = 'skeleton' . "\1" . $skeleton;
            }
            $this->bump($acc, 'skeleton', sha1($hash), self::clip($skeleton), '', $line);
            return;
        }

        if ($node instanceof Node\Scalar\String_) {
            if (strlen($node->value) >= self::STRING_MIN_LEN) {
                $this->bump($acc, 'string', sha1($node->value), self::clip($node->value), '', $line);
            }
            return;
        }

        if ($node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) {
            // Array members belong to the 'array' bucket signature.
            if ($parent instanceof Node\ArrayItem) {
                return;
            }
            $value = $node instanceof Node\Scalar\Int_ ? (string) $node->value : var_export($node->value, true);
            if ($value === '0' || $value === '1' || $value === '-1') {
                return; // trivial counters, not magic values
            }
            $pos = $parent !== null ? (new \ReflectionClass($parent))->getShortName() : 'none';
            $this->bump($acc, 'number', sha1($value . "\1" . $pos), $value . ' @' . $pos, '', $line);
            return;
        }

        if ($node instanceof Node\Expr\Array_) {
            // A nested array is part of its parent's signature; the
            // outer literal owns the entry.
            if ($parent instanceof Node\ArrayItem) {
                return;
            }
            if (count($node->items) < self::MIN_ARRAY_ITEMS) {
                return;
            }
            $sigs = [];
            foreach ($node->items as $item) {
                if (!$item instanceof Node\ArrayItem) {
                    return; // spread element: shape not comparable
                }
                $sigs[] = self::arrayEntrySig($item);
            }
            sort($sigs); // ORDER-INSENSITIVE by construction (bullet 2)
            $this->bump($acc, 'array', sha1(implode("\1", $sigs)), self::clip(implode(', ', array_slice($sigs, 0, 4))), '', $line);
            return;
        }

        if ($node instanceof Node\Stmt\ClassConst) {
            foreach ($node->consts as $const) {
                // The group is the NAME (cross-class copies are the
                // story); the sample states exactly that content — the
                // writing class is readable from the row's locations.
                $this->bump($acc, 'const', sha1('NAME' . "\1" . $const->name->toString()), self::clip($const->name->toString()), 'decl', $line);
            }
            return;
        }

        if ($node instanceof Node\Stmt\EnumCase) {
            $this->bump($acc, 'const', sha1('NAME' . "\1" . $node->name->toString()), self::clip($node->name->toString()), 'enum_case', $line);
            return;
        }

        if ($node instanceof Node\Expr\ClassConstFetch && $node->name instanceof Node\Identifier) {
            // Group key = NAME alone (cross-class copies are the point);
            // the sample states the same content as the key promises.
            $this->bump($acc, 'const', sha1('NAME' . "\1" . $node->name->toString()), self::clip($node->name->toString()), 'use', $line);
            return;
        }
    }

    /**
     * Accumulator keys ride (kind, hash, role): a file that declares a
     * constant and fetches it contributes two bounded rows under one
     * group hash, and the master keeps the roles apart.
     *
     * @param array<string, array{kind:string,hash:string,sample:string,role:string,count:int,lines:list<int>}> $acc
     */
    private function bump(array &$acc, string $kind, string $hash, string $sample, string $role, int $line): void
    {
        $key = $kind . "\0" . $hash . "\0" . $role;
        if (!isset($acc[$key])) {
            $acc[$key] = ['kind' => $kind, 'hash' => $hash, 'sample' => $sample, 'role' => $role, 'count' => 0, 'lines' => []];
        }
        $acc[$key]['count']++;
        if (count($acc[$key]['lines']) < self::ROW_CAP) {
            $acc[$key]['lines'][] = $line;
        }
    }

    /** Human skeleton: literal fragments verbatim, expressions collapsed. */
    private static function interpolationSkeleton(Node\Scalar\InterpolatedString $node): string
    {
        $out = '';
        foreach ($node->parts as $part) {
            if ($part instanceof Node\InterpolatedStringPart) {
                $out .= $part->value;
                continue;
            }
            $out .= '?';
        }
        return $out;
    }

    private static function arrayEntrySig(Node\ArrayItem $item): string
    {
        $key = $item->key !== null ? self::scalarText($item->key) ?? '?' : '';
        return $key . '=>' . (self::scalarText($item->value) ?? '?');
    }

    /** Compact scalar reading, or null for anything dynamic. */
    private static function scalarText(Node $n): ?string
    {
        if ($n instanceof Node\Scalar\String_) {
            return '"' . self::clip($n->value, 40) . '"';
        }
        if ($n instanceof Node\Scalar\Int_) {
            return (string) $n->value;
        }
        if ($n instanceof Node\Scalar\Float_) {
            return var_export($n->value, true);
        }
        if ($n instanceof Node\Expr\ConstFetch) {
            return $n->name->toString();
        }
        if ($n instanceof Node\Expr\ClassConstFetch && $n->name instanceof Node\Identifier) {
            $cls = $n->class instanceof Node\Name
                ? substr($n->class->toString(), (int) strrpos($n->class->toString(), '\\') + 1)
                : '?';
            return $cls . '::' . $n->name->name;
        }
        if ($n instanceof Node\Expr\Array_) {
            $parts = [];
            foreach ($n->items as $item) {
                if ($item instanceof Node\ArrayItem) {
                    $parts[] = self::arrayEntrySig($item);
                }
            }
            sort($parts);
            return '[' . implode(', ', $parts) . ']';
        }
        return null;
    }

    private static function clip(string $v, int $cap = self::SAMPLE_MAX_BYTES): string
    {
        $v = str_replace(["\r", "\n", "\t"], ['\r', '\n', '\t'], $v);
        return strlen($v) <= $cap ? $v : substr($v, 0, $cap - 1) . "\u{2026}";
    }
}
