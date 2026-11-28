<?php
declare(strict_types=1);

namespace Phpdup\Census;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Semantic\CallGraph;

/**
 * Ordered callee-name capture for the call-sequence census (item 3.5
 * letter, part 1): "per function, record the ordered list of callee
 * names (CallGraph already collects the set)".
 *
 * ONE walker law (§5c): like {@see SubtreeMiner}, this class performs
 * no parsing and no file reads — it consumes the statement lists the
 * BlockExtractor's single traversal already collected (the same
 * $listEntry shape the 2.1 stmtseq leg and the 3.1 idiom leg mine in
 * PreprocessWorker), so enabling the 'calls' flavor never adds a
 * second pass over the corpus. The capture semantics are CallGraph's
 * own (its {@see CallGraph::nameOf()} is the public callee-name
 * contract: MethodCall receiver-blind, StaticCall `Class::method`,
 * FuncCall qualified name, dynamic shapes null = never guessed); what
 * the idiom of 3.5 adds is ORDER — NodeFinder answers in AST child
 * order, which for statements and arguments is program order (the
 * one disclosed deviation: a chained `$a->b()->c()` visits the OUTER
 * call first, document order would visit `b` first — chains are
 * rare and the preorder listing is deterministic).
 *
 * Constant-argument witnesses ride the same walk (the doc's "table of
 * constant arguments that differ"): per call, the scalar-ish argument
 * tokens — direct ConstFetch/Int_/String_/Float_ args, and array
 * literal items as `KEY=>value` pair tokens (the CURLOPT_SSL_VERIFYPEER
 * => false archetype; plain arrays of constants become their item
 * tokens). Variables and dynamic expressions are holes: they carry no
 * token at that position (matching the "replace constant args with
 * name tokens" ruling — the sequence key never sees args, only the
 * per-slot table does).
 *
 * Per-file payload (scalar-only, SOCKETPAIR law; pre-aggregated so the
 * wire stays bounded):
 *   list of
 *   { names: list<string>,            // ordered callee names (cap below)
 *     lines: list<int>,               // call start lines, index-parallel
 *     args:  list<list<string>>,      // per-call constant-arg tokens
 *     firstLine: int, lastLine: int } // the list's span (context only)
 * Caps: MAX_CALLS_PER_LIST ordered calls, MAX_ARG_TOKENS_PER_CALL
 * tokens per call, TOKEN_MAX_BYTES per token (truncated),
 * MAX_LISTS_PER_FILE lists per file (document order, tail dropped).
 */
final class CallSequenceMiner
{
    /** Ordered calls kept per statement list (truncation, disclosed). */
    public const MAX_CALLS_PER_LIST = 64;

    /** Constant-arg tokens kept per call (truncation, disclosed). */
    public const MAX_ARG_TOKENS_PER_CALL = 12;

    /** Byte cap of one argument token (truncation, disclosed). */
    public const TOKEN_MAX_BYTES = 80;

    /** Statement lists carried per file (document order; tail dropped). */
    public const MAX_LISTS_PER_FILE = 200;

    /**
     * Mine one file from the already-collected statement lists.
     *
     * @param list<array{stmts: list<Node\Stmt>, namespace: string, class: string, ownerKind: string}> $lists
     * @return list<array{names: list<string>, lines: list<int>, args: list<list<string>>, firstLine: int, lastLine: int}>
     */
    public function mineFile(array $lists): array
    {
        $records = [];
        $finder  = new NodeFinder();
        foreach ($lists as $entry) {
            if (count($records) >= self::MAX_LISTS_PER_FILE) {
                break; // document order, tail dropped (cap, disclosed)
            }
            $stmts = $entry['stmts'];
            if ($stmts === []) {
                continue; // the extractor never collects empty lists
            }
            $first = $stmts[0]->getStartLine();
            $last  = $stmts[count($stmts) - 1]->getEndLine();
            if ($first <= 0 || $last <= 0 || $last < $first) {
                continue; // same line-guard every mining flavor applies
            }
            $names = [];
            $lines = [];
            $args  = [];
            foreach ($finder->find($stmts, static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall
                || $n instanceof Node\Expr\StaticCall
                || $n instanceof Node\Expr\FuncCall) as $call) {
                /** @var Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\FuncCall $call */
                if (count($names) >= self::MAX_CALLS_PER_LIST) {
                    break; // ordered calls capped (disclosed)
                }
                $name = CallGraph::nameOf($call);
                if ($name === null) {
                    continue; // dynamic callee — no name, never guessed
                }
                $names[] = $name;
                $lines[] = max(1, $call->getStartLine());
                $args[]  = self::constantArgTokens($call->args);
            }
            if ($names === []) {
                continue; // a list with no named calls carries no sequence
            }
            $records[] = [
                'names'     => $names,
                'lines'     => $lines,
                'args'      => $args,
                'firstLine' => $first,
                'lastLine'  => $last,
            ];
        }
        return $records;
    }

    /**
     * Constant-argument witness tokens of one call site (holes are
     * simply absent — sequence matching is arg-blind by design).
     *
     * @param array<int, Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args
     * @return list<string>
     */
    private static function constantArgTokens(array $args): array
    {
        $tokens = [];
        foreach ($args as $arg) {
            if (count($tokens) >= self::MAX_ARG_TOKENS_PER_CALL) {
                break;
            }
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $direct = self::scalarToken($arg->value);
            if ($direct !== null) {
                $tokens[] = $direct;
                continue;
            }
            if ($arg->value instanceof Node\Expr\Array_) {
                foreach ($arg->value->items as $item) {
                    if (count($tokens) >= self::MAX_ARG_TOKENS_PER_CALL) {
                        break;
                    }
                    if (!$item instanceof Node\ArrayItem) {
                        continue;
                    }
                    $key   = $item->key !== null ? self::scalarToken($item->key) : null;
                    $value = self::scalarToken($item->value);
                    if ($key !== null && $value !== null) {
                        $tokens[] = self::clip($key . '=>' . $value);
                    } elseif ($value !== null) {
                        $tokens[] = self::clip($value);
                    }
                }
            }
        }
        return $tokens;
    }

    /** Scalar-ish reading of one expression, or null (hole). */
    private static function scalarToken(Node $n): ?string
    {
        if ($n instanceof Node\Expr\ConstFetch) {
            return $n->name->toString();
        }
        if ($n instanceof Node\Scalar\String_) {
            return self::clip($n->value);
        }
        if ($n instanceof Node\Scalar\Int_) {
            return (string) $n->value;
        }
        if ($n instanceof Node\Scalar\Float_) {
            return \var_export($n->value, true); // locale-independent '.' decimal
        }
        if ($n instanceof Node\Expr\ClassConstFetch && $n->name instanceof Node\Identifier) {
            $class = $n->class instanceof Node\Name
                ? substr($n->class->toString(), (int) strrpos($n->class->toString(), '\\') + 1)
                : '?';
            return $class . '::' . $n->name->name;
        }
        return null;
    }

    private static function clip(string $v): string
    {
        return strlen($v) <= self::TOKEN_MAX_BYTES
            ? $v
            : substr($v, 0, self::TOKEN_MAX_BYTES - 1) . "\u{2026}";
    }
}
