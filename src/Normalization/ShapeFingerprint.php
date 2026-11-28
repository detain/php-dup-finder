<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

use PhpParser\Node\Identifier;
use PhpParser\Node;
use Phpdup\Extraction\Block;
use Phpdup\Util\LineRange;
use Phpdup\Fingerprint\SubtreeHasher;

/**
 * Item 2.3 — the class-declaration SHAPE fingerprint (opt-in 'class' kind).
 *
 * The doc's letter: a class block is fingerprinted by its whole-class
 * TOKENS — member names with arity and visibility, constant names,
 * implemented interfaces — not by what its methods do. That is precisely
 * what this class extracts, on top of the groundwork the info-doc names
 * (improve_logic.md item 2.3; improve_logic_info.md §2 Phase-2/2.3):
 *
 *   - {@see skeleton()}: the whole-class token identity. A deep clone of
 *     the pipeline's CANONICAL class node with its own declaration name
 *     replaced by the {@see CLASS_NAME_SENTINEL} blank — everything else
 *     (member kinds/order, visibility+static+abstract flags, param
 *     counts, property/return types, const names, interface names, enum
 *     cases) rides through the existing SubtreeHasher + NgramFingerprint
 *     chain VERBATIM. Default-mode canonicalisation already blanks
 *     literals and collapses `__FN` method labels (engine-036(b)), so
 *     name-blanking the declaration itself is the ONE remaining step to
 *     make "same shape, different name" twins hash byte-identically —
 *     the doc's RateLimitState / ServiceProviderInterface pair, made
 *     findable. Extends/implements names deliberately SURVIVE the blank:
 *     the doc counts implemented interfaces as shape tokens.
 *   - {@see memberSignatures()}: the doc's literal token list — one flat
 *     readable string per member (plus a leading `kind:` row carrying
 *     species/final-abstract/extends/implements), computed from the RAW
 *     AST so declared NAMES are verbatim (the canonical tree blanks
 *     method labels). This is the cluster's shape table, rendered into
 *     the JSON `class_shape` key.
 *   - {@see methodHashes()}: the skeleton-family table's fuel. Per RAW
 *     method name, two structural hashes: `s` (strict fold — literals,
 *     variable names and declaration names verbatim) and `d` (the run's
 *     own normalizer — the pipeline's fold). The doc's three-way verdict
 *     then falls out mechanically: one `s` across the cluster =
 *     identical-in-all (pull up as-is); one `d` but several `s` =
 *     parameterizable (same shape modulo literals/vars — the template-
 *     method proposal); several `d` = divergent.
 *
 * Posture law (f08 risk9): NOTHING here touches the scoring hot path.
 * These functions run ONLY inside the PreprocessWorker fold's
 * `kind === CLASS_KIND` arm — a class block's regular structuralHash/
 * ngramBag are REPLACED by the skeleton's products (the whole-unit
 * identity of a class IS its shape; its method bodies are already their
 * own 'method' blocks when that kind is on the roster). Default runs
 * never mint class blocks (BlockExtractor's never-implicit mint gate),
 * so the whole module is inert unless the user names `--kinds class`.
 *
 * The skeleton-family clustering v1 rides the EXISTING similarity
 * machinery (ngram Jaccard + TED over skeletons, size-capped by the
 * ordinary max_block_size / max_ted_size walls). The order-tolerant
 * member-SET Jaccard floor (~0.7) the info-doc derives from the ×64
 * Plugin.php probe is the DEFAULT-ON half's concern (owner-gated with
 * the private-corpus benchmark, §6#2) and stays a follow-on row —
 * shapeMembers tokens below are its data source when it lands.
 */
final class ShapeFingerprint
{
    /**
     * Blank for a class's own declaration name (sibling of the default
     * canonicaliser's `__FN` container-label collapse, Normalizer.php
     * :593). `__`-prefixed so no legal PHP declaration name collides.
     */
    public const CLASS_NAME_SENTINEL = '__CLASS__';

    /** The strict-lane twin used for per-method `s` hashes (see foldStrict). */
    private static ?Normalizer $strictLane = null;

    /**
     * Name-blank clone of a canonicalized class-like node.
     *
     * @param Node $classLike the block's canonical tree (a Stmt\ClassLike
     *                        after Normalizer::normalize(); the caller
     *                        guarantees the species)
     */
    public static function skeleton(Node $classLike): Node
    {
        $clone = Normalizer::deepClone($classLike);
        if ($clone instanceof Node\Stmt\Class_
            || $clone instanceof Node\Stmt\Interface_
            || $clone instanceof Node\Stmt\Trait_
            || $clone instanceof Node\Stmt\Enum_
        ) {
            $clone->name = new Identifier(self::CLASS_NAME_SENTINEL);
        }
        return $clone;
    }

    /**
     * The doc's shape tokens, one readable string per member, declared
     * order, computed from the RAW AST (names verbatim).
     *
     * Row grammar (stable, machine-greppable; `vis` is public|protected|
     * private, `t` = static flag, `a` = abstract, `f` = final):
     *   kind:<species>[:final][:abstract][:backing=<t>]:ext:<parent>|:impl:<a,b>|:use:<t>
     *   c:<vis>:<CONST_NAME>
     *   p:<vis>[:t][:r]:<name>[:<type>]
     *   m:<vis>[:t][:a|f]:<name>/<arity>[:<returnType>]
     *   case:<NAME>
     *
     * @return list<string>
     */
    public static function memberSignatures(Node $classLike): array
    {
        if (!$classLike instanceof Node\Stmt\ClassLike) {
            return [];
        }
        $out = [self::declarationRow($classLike)];

        foreach ($classLike->stmts ?? [] as $member) {
            if ($member instanceof Node\Stmt\ClassConst) {
                $vis = self::visibility($member->flags);
                foreach ($member->consts as $const) {
                    $out[] = 'c:' . $vis . ':' . $const->name->toString();
                }
            } elseif ($member instanceof Node\Stmt\Property) {
                $t = self::typeName($member->type);
                foreach ($member->props as $prop) {
                    // PropertyItem->name is a Variable: plain string for
                    // legal declarations, a node only for the (illegal)
                    // variable-variable shapes.
                    $pname = is_string($prop->name->name) ? $prop->name->name : '?';
                    $out[] = 'p:' . self::visibility($member->flags)
                        . (($member->flags & Node\Stmt\Class_::MODIFIER_STATIC) !== 0 ? ':t' : '')
                        . ($member->isReadonly() ? ':r' : '')
                        . ':' . $pname
                        . ($t !== null ? ':' . $t : '');
                }
            } elseif ($member instanceof Node\Stmt\ClassMethod) {
                $t = self::typeName($member->returnType);
                $out[] = 'm:' . self::visibility($member->flags)
                    . ($member->isStatic() ? ':t' : '')
                    . ($member->isAbstract() ? ':a' : ($member->isFinal() ? ':f' : ''))
                    . ':' . $member->name->toString()
                    . '/' . count($member->params)
                    . ($t !== null ? ':' . $t : '');
            } elseif ($member instanceof Node\Stmt\EnumCase) {
                $out[] = 'case:' . $member->name->toString();
            } elseif ($member instanceof Node\Stmt\TraitUse) {
                foreach ($member->traits as $trait) {
                    $out[] = 'use:' . $trait->toString();
                }
            }
        }
        return $out;
    }

    /**
     * Per RAW method name: the skeleton-family table's two hashes.
     *
     * `d` folds the method through the RUN's own normalizer instance —
     * identical treatment the enclosing block's canonical tree got (zero
     * divergence by construction, and no second option-matrix to keep in
     * sync). `s` folds through a process-wide strict-lane twin (strict
     * mode is option-free at the fingerprint level: every class block in
     * a run meets the same instance, so cluster-wide comparison is
     * consistent by the same argument).
     *
     * Abstract/interface methods (stmts === null) carry the empty-body
     * token for both lanes — a twin pair of interfaces declares
     * identical-in-all method rows, exactly the doc's
     * ServiceProviderInterface case.
     *
     * @return array<string, array{s:string, d:string}>
     */
    public static function methodHashes(
        Node $classLike,
        Normalizer $runNormalizer,
        SubtreeHasher $hasher,
    ): array {
        if (!$classLike instanceof Node\Stmt\ClassLike) {
            return [];
        }
        $strict = self::strictLane();
        $out    = [];
        foreach ($classLike->getMethods() as $method) {
            $name = $method->name->toString();
            $out[$name] = [
                's' => self::hashMethod($method, $strict, $hasher),
                'd' => self::hashMethod($method, $runNormalizer, $hasher),
            ];
        }
        return $out;
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * One strict-lane normalizer per process (PreprocessWorker folds are
     * per-file loops; the instance is stateless between normalize()
     * calls, so sharing it is what the engine-034 handoff already does
     * for the hasher).
     */
    private static function strictLane(): Normalizer
    {
        return self::$strictLane ??= new Normalizer('strict');
    }

    private static function hashMethod(
        Node\Stmt\ClassMethod $method,
        Normalizer $normalizer,
        SubtreeHasher $hasher,
    ): string {
        // Synthetic single-method carrier: same fold path any 'method'
        // block takes, so the `d` lane lands on exactly the bytes the
        // pipeline would have hashed for that method kind.
        $carrier = new Block(
            file: '',
            range: new LineRange(0, 0),
            kind: 'method',
            namespace: null,
            class: null,
            name: $method->name->toString(),
            ast: $method,
        );
        $normalizer->normalize($carrier);
        return $hasher->hash($carrier->canonical);
    }

    private static function declarationRow(Node\Stmt\ClassLike $classLike): string
    {
        $species = match (true) {
            $classLike instanceof Node\Stmt\Interface_ => 'interface',
            $classLike instanceof Node\Stmt\Trait_     => 'trait',
            $classLike instanceof Node\Stmt\Enum_      => 'enum',
            default                                    => 'class',
        };
        $row = 'kind:' . $species;
        // php-parser 5: ->flags/isFinal()/isAbstract() live on Class_
        // only (Enum_ has no flags property and final/abstract enums are
        // not legal PHP; interfaces/traits cannot carry them either).
        if ($classLike instanceof Node\Stmt\Class_) {
            if (($classLike->flags & Node\Stmt\Class_::MODIFIER_FINAL) !== 0) {
                $row .= ':final';
            }
            if ($classLike->isAbstract()) {
                $row .= ':abstract';
            }
        }
        if ($classLike instanceof Node\Stmt\Enum_ && $classLike->scalarType !== null) {
            $row .= ':backing=' . (self::typeName($classLike->scalarType) ?? '?');
        }
        if ($classLike instanceof Node\Stmt\Class_ && $classLike->extends !== null) {
            $row .= ':ext:' . $classLike->extends->toString();
        }
        $implements = [];
        if ($classLike instanceof Node\Stmt\Class_ || $classLike instanceof Node\Stmt\Enum_) {
            foreach ($classLike->implements as $iface) {
                $implements[] = $iface->toString();
            }
        } elseif ($classLike instanceof Node\Stmt\Interface_) {
            // php-parser 5: an interface's parent interfaces live in
            // ->extends (there is no $implements on Interface_). They are
            // the doc's implemented-interfaces token for that species —
            // merged into the same sorted bucket.
            foreach ($classLike->extends as $iface) {
                $implements[] = $iface->toString();
            }
        }
        if ($implements !== []) {
            sort($implements);
            $row .= ':impl:' . implode(',', $implements);
        }
        return $row;
    }

    private static function visibility(int $flags): string
    {
        if (($flags & Node\Stmt\Class_::MODIFIER_PRIVATE) !== 0) {
            return 'private';
        }
        if (($flags & Node\Stmt\Class_::MODIFIER_PROTECTED) !== 0) {
            return 'protected';
        }
        return 'public';
    }

    /**
     * A type node as one comparable string (names/identifiers verbatim —
     * these are shape, not scope). Complex types flatten through their
     * named leaves; null (no type) collapses to null.
     */
    private static function typeName(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . (self::typeName($type->type) ?? '?');
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $parts = [];
            foreach ($type->types as $part) {
                $parts[] = self::typeName($part) ?? '?';
            }
            return implode($type instanceof Node\UnionType ? '|' : '&', $parts);
        }
        // DNF types (ParenthesizedType) delegate; any other exotic
        // shape prints its class short-name (stable + visible in the
        // table rather than silently dropped).
        if ($type instanceof Node\ComplexType) {
            return self::typeName($type->type) ?? '?';
        }
        return substr(strrchr('\\' . $type::class, '\\'), 1);
    }
}
