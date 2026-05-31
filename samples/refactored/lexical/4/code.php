<?php
declare(strict_types=1);

namespace Acme\Common\Build;

/**
 * Apply a stack of mutations to a builder instance and finalize it.
 *
 * @template TBuilder of object
 * @template TResult
 */
final class ChainedBuild
{
    /**
     * @param TBuilder                          $builder
     * @param array<int, callable(TBuilder): TBuilder> $mutators
     * @param callable(TBuilder): TResult       $finalize
     * @return TResult
     */
    public static function apply(object $builder, array $mutators, callable $finalize): mixed
    {
        foreach ($mutators as $mutate) {
            $builder = $mutate($builder);
        }
        return $finalize($builder);
    }
}

// Per-domain usage
// return ChainedBuild::apply(
//     new SelectBuilder(),
//     [
//         fn($b) => $b->withTable('users'),
//         fn($b) => $b->withColumns(['id','email','role']),
//         fn($b) => $b->withCondition('role = ?', $role),
//         fn($b) => $b->withCondition('age >= ?', $minAge),
//     ],
//     fn($b) => $b->build(),
// );
