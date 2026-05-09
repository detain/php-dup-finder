<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Phpdup\Clustering\Cluster;
use Phpdup\Normalization\Normalizer;
use Phpdup\Util\AstSerializer;

/**
 * Computes the most-specific generalization of a cluster's members.
 *
 * Uses path-based hole tracking rather than embedded sentinel nodes:
 * the template is left untouched (it's a deep clone of member[0]'s
 * AST), and divergences are recorded as Hole objects with the path
 * through the AST and the observed values per member.
 *
 * Algorithm: for each subsequent member i ≥ 1, walk member[0] and
 * member[i] in parallel. At each path P:
 *
 *   - if a hole already exists at P (from a prior member): record
 *     member[i]'s value there; stop recursing into this subtree
 *   - if nodes match structurally and any leaf scalars match: recurse
 *   - otherwise: NEW hole at P. Members 0..i-1 implicitly agreed with
 *     member[0]'s value at P (otherwise a hole would already exist),
 *     so backfill i copies of that, then record member[i]'s value.
 */
final class AntiUnifier
{
    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function unify(Cluster $cluster): void
    {
        $members = $cluster->members;
        if (count($members) === 0) {
            return;
        }
        if (count($members) === 1) {
            $cluster->generalizedAst = Normalizer::deepClone($members[0]->ast);
            $cluster->holes = [];
            return;
        }

        $ctx = new UnifyContext();
        for ($i = 1; $i < count($members); $i++) {
            $this->walk($members[0]->ast, $members[$i]->ast, $i, $ctx, []);
        }

        $cluster->generalizedAst = Normalizer::deepClone($members[0]->ast);
        $cluster->holes = array_values($ctx->holes);
    }

    /**
     * @param list<int|string> $path
     */
    private function walk(?Node $template, ?Node $member, int $memberIdx, UnifyContext $ctx, array $path): void
    {
        $key = self::pathKey($path);
        if (isset($ctx->holesByPath[$key])) {
            $ctx->holesByPath[$key]->appendObserved($this->repr($member));
            return;
        }

        if ($template === null && $member === null) {
            return;
        }
        if ($template === null || $member === null || $template::class !== $member::class) {
            $this->createHole($path, $template, $member, $memberIdx, $ctx, 'subtree');
            return;
        }

        $tScalar = AstSerializer::scalarPart($template);
        $mScalar = AstSerializer::scalarPart($member);
        if ($tScalar !== null && $mScalar !== null) {
            if ($tScalar === $mScalar) {
                return;
            }
            $this->createHole($path, $template, $member, $memberIdx, $ctx, $this->kindForLeaf($template));
            return;
        }

        foreach ($template->getSubNodeNames() as $sub) {
            if (self::shouldSkipSubnode($template, $sub)) {
                continue;
            }
            $tVal = $template->$sub;
            $mVal = $member->$sub;

            if ($tVal instanceof Node && $mVal instanceof Node) {
                $this->walk($tVal, $mVal, $memberIdx, $ctx, [...$path, $sub]);
                continue;
            }
            if (is_array($tVal) && is_array($mVal)) {
                if (count($tVal) !== count($mVal)) {
                    $this->createHole([...$path, $sub], $template, $member, $memberIdx, $ctx, 'subtree');
                    continue;
                }
                foreach ($tVal as $idx => $tChild) {
                    $mChild = $mVal[$idx];
                    if ($tChild instanceof Node && $mChild instanceof Node) {
                        $this->walk($tChild, $mChild, $memberIdx, $ctx, [...$path, $sub, $idx]);
                    } elseif ($tChild !== $mChild && !($tChild === null && $mChild === null)) {
                        // mixed-type array element divergence — rare
                        $this->createHole([...$path, $sub, $idx], null, null, $memberIdx, $ctx, 'subtree');
                    }
                }
                continue;
            }
            if ($tVal === $mVal) {
                continue;
            }
            if ($tVal === null xor $mVal === null) {
                // optional subnode present in one and not the other
                $this->createHole([...$path, $sub], null, null, $memberIdx, $ctx, 'subtree');
                continue;
            }
            // scalar attribute mismatch (e.g. flags) — treat as subtree hole at this position
            $this->createHole($path, $template, $member, $memberIdx, $ctx, 'subtree');
            return;
        }
    }

    /**
     * @param list<int|string> $path
     */
    private function createHole(array $path, ?Node $tNode, ?Node $mNode, int $memberIdx, UnifyContext $ctx, string $kind): void
    {
        $key = self::pathKey($path);
        if (isset($ctx->holesByPath[$key])) {
            $ctx->holesByPath[$key]->appendObserved($this->repr($mNode));
            return;
        }
        $holeId = '__P' . ($ctx->holeCounter++);
        $hole = new Hole($holeId, $kind);
        $tRepr = $this->repr($tNode);
        for ($i = 0; $i < $memberIdx; $i++) {
            $hole->appendObserved($tRepr);
        }
        $hole->appendObserved($this->repr($mNode));
        $ctx->holes[$holeId] = $hole;
        $ctx->holesByPath[$key] = $hole;
    }

    private function kindForLeaf(Node $template): string
    {
        if ($template instanceof Node\Scalar\String_)            return 'literal';
        if ($template instanceof Node\Scalar\Int_)               return 'literal';
        if ($template instanceof Node\Scalar\Float_)             return 'literal';
        if ($template instanceof Node\Scalar\InterpolatedString) return 'literal';
        if ($template instanceof Node\Expr\Variable)             return 'identifier';
        if ($template instanceof Node\Identifier)                return 'name';
        if ($template instanceof Node\Name)                      return 'name';
        if ($template instanceof Node\VarLikeIdentifier)         return 'name';
        return 'subtree';
    }

    /**
     * Container-label subnodes that mustn't become holes — those are
     * what the suggested abstraction's *name* will be, not parameters.
     */
    private static function shouldSkipSubnode(Node $node, string $subnode): bool
    {
        if ($subnode !== 'name') return false;
        return $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_;
    }

    /** @param list<int|string> $path */
    private static function pathKey(array $path): string
    {
        return implode('/', array_map('strval', $path));
    }

    private function repr(?Node $node): string
    {
        if ($node === null) return '<missing>';
        if ($node instanceof Node\Scalar\String_)     return var_export($node->value, true);
        if ($node instanceof Node\Scalar\Int_)        return (string)$node->value;
        if ($node instanceof Node\Scalar\Float_)      return (string)$node->value;
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) return '$' . $node->name;
        if ($node instanceof Node\Identifier)         return $node->name;
        if ($node instanceof Node\Name)               return $node->toString();
        if ($node instanceof Node\VarLikeIdentifier)  return '$' . $node->name;

        try {
            if ($node instanceof Node\Expr) {
                return $this->printer->prettyPrintExpr($node);
            }
            if ($node instanceof Node\Stmt) {
                return trim($this->printer->prettyPrint([$node]));
            }
        } catch (\Throwable) {
            // fall through
        }
        return '<' . AstSerializer::shortType($node) . '>';
    }
}

/** @internal */
final class UnifyContext
{
    public int $holeCounter = 0;
    /** @var array<string,Hole> by holeId */
    public array $holes = [];
    /** @var array<string,Hole> by pathKey */
    public array $holesByPath = [];
}
