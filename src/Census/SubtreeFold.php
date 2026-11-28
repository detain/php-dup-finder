<?php
declare(strict_types=1);

namespace Phpdup\Census;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\Node\Scalar;
use Phpdup\Normalization\Normalizer;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\Delimiters;

/**
 * Dedicated bottom-up fold for idiom-subtree keys (item 3.1, §5d of
 * improve_logic_info.md: "idiom/subtree keys need a DEDICATED BOTTOM-UP
 * FOLD — NOT Normalizer modes").
 *
 * WHY not the Normalizer: CanonicalizingVisitor is a block-scoped state
 * machine — its `__V` numbering is dealt by first appearance inside the
 * ENCLOSING BLOCK, so the same subtree digests differently depending on
 * what precedes it. An idiom census compares subtrees extracted from
 * unrelated positions, so numbering must be SUBTREE-LOCAL: each folded
 * copy gets a fresh counter, dealt in first-appearance order of this
 * very subtree (the traversal below visits children in exactly the
 * order AstSerializer::walk() serialises them, so slot order and token
 * order can never disagree).
 *
 * WHY not a second token formula: the hash layer IS the shared
 * primitive StmtHash stands on — AstSerializer::tokens() joined by
 * Delimiters::HASH_JOIN and sha1'd. The fold normalises DIFFERENT
 * content (subtree-local roles), but no competing tokenisation exists;
 * §5c's same-concept law is honoured by construction (§5d names the
 * subtree key a distinct concept whose local scoping is the point).
 *
 * Two keys per subtree, per the 3.1 letter:
 *  - EXACT    — variables renumbered subtree-locally, call/class/
 *               member names and literal VALUES kept.
 *  - ABSTRACT — additionally blanks literal leaves. [f09] named the
 *               gap this closes: Normalizer::canonicalizeLiterals()
 *               never touches bareword ConstFetch, so `true`, `null`
 *               and `PHP_INT_MAX` leak into "literal-free" keys. Here
 *               EVERY global-constant fetch (a bareword is
 *               indistinguishable from a literal-value reference
 *               without symbol resolution) folds to `__CONST`, scalars
 *               fold to the same sentinels the Normalizer uses
 *               (`__STR`, int 0, float 0.0, interpolated parts
 *               `__STR`), and `self/static/parent::CONST` member slots
 *               fold to `__CONST` while the CLASS name is kept: a
 *               class-qualified member carries type identity, not a
 *               literal value.
 *
 * $this is preserved verbatim (semantically distinct receiver — the
 * identical convention canonicalizeVariables() applies), and
 * already-generated `__V<n>` names from an upstream fold are treated as
 * opaque user names of THIS subtree (first-appearance renumbering still
 * maps them to their own slot; the alias guard from engine-055 is
 * reproduced in miniature because a subtree lifted out of a canonical
 * block can carry `__V3` as its real name and must not collide with a
 * slot dealt to `$foo` in the same fold).
 */
final class SubtreeFold
{
    /** Sentinel for a blanked bareword constant fetch (abstract key). */
    public const CONST_SENTINEL = '__CONST';

    /**
     * @param array<string,bool>    $userVarNames real variable names of this subtree
     * @param array<string,string>  $varRenames   original name => dealt slot (mutable map)
     * @param array<string,true>    $seenBySlot   slots dealt by THIS fold (mutable set)
     */
    private function __construct(
        private readonly bool $blankLiterals,
        private readonly array $userVarNames,
        private array $varRenames = [],
        private array $seenBySlot = [],
        private int $varCounter = 0,
    ) {
    }

    /**
     * Both keys for one subtree in a single walk. The two folds are
     * independent clones, so neither result leaks into the other.
     *
     * @return array{exact: string, abstract: string}
     */
    public static function hashPair(Node $subtree): array
    {
        return [
            'exact'    => self::hashWith($subtree, false),
            'abstract' => self::hashWith($subtree, true),
        ];
    }

    public static function hashExact(Node $subtree): string
    {
        return self::hashWith($subtree, false);
    }

    public static function hashAbstract(Node $subtree): string
    {
        return self::hashWith($subtree, true);
    }

    private static function hashWith(Node $subtree, bool $blankLiterals): string
    {
        $folded  = Normalizer::deepClone($subtree);
        $userVar = [];
        foreach ((new NodeFinder())->findInstanceOf($folded, Expr\Variable::class) as $v) {
            if (is_string($v->name)) {
                $userVar[$v->name] = true;
            }
        }
        (new self($blankLiterals, $userVar))->fold($folded);
        return sha1(implode(Delimiters::HASH_JOIN, AstSerializer::tokens($folded)));
    }

    /** Deterministic pre-order rewrite; children in serialisation order. */
    private function fold(Node $node): void
    {
        $this->foldNode($node);
        foreach ($node->getSubNodeNames() as $sub) {
            $value = $node->$sub;
            if ($value instanceof Node) {
                $this->fold($value);
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->fold($child);
                    }
                }
            }
        }
    }

    private function foldNode(Node $node): void
    {
        // Variable renames first (pre-order, matching walk() emit order).
        if ($node instanceof Expr\Variable) {
            if (is_string($node->name) && $node->name !== 'this') {
                $node->name = $this->slotFor($node->name);
            }
            return;
        }

        if (!$this->blankLiterals) {
            return;
        }

        if ($node instanceof Scalar\String_) {
            $node->value = '__STR';
            return;
        }
        if ($node instanceof Scalar\Int_) {
            $node->value = 0;
            return;
        }
        if ($node instanceof Scalar\Float_) {
            $node->value = 0.0;
            return;
        }
        if ($node instanceof Scalar\InterpolatedString) {
            foreach ($node->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart) {
                    $part->value = '__STR';
                }
            }
            return;
        }
        if ($node instanceof Expr\ConstFetch) {
            $node->name = new Node\Name(self::CONST_SENTINEL);
            return;
        }
        if ($node instanceof Expr\ClassConstFetch && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier(self::CONST_SENTINEL);
        }
    }

    /**
     * First-appearance slot deal, engine-055 alias guard in miniature:
     * a `__V<n>`-shaped name that this fold has not itself dealt is an
     * opaque user name (it rode in from an upstream fold) and passes
     * through un-renumbered; a dealt name always maps back to its slot.
     */
    private function slotFor(string $original): string
    {
        if (isset($this->varRenames[$original])) {
            return $this->varRenames[$original];
        }
        if (preg_match('/^__V\d+$/', $original) === 1 && !isset($this->seenBySlot[$original])) {
            return $original; // opaque pass-through: not this fold's own artifact
        }
        do {
            $token = '__V' . $this->varCounter++;
        } while (isset($this->userVarNames[$token]) || isset($this->varRenames[$token]));
        $this->varRenames[$original] = $token;
        $this->seenBySlot[$token]    = true;
        return $token;
    }
}
