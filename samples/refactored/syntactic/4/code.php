<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * Generic cursor-fed accumulator. The caller plugs in a cursor factory, a
 * per-item reducer that mutates the running accumulator, and a finaliser
 * that turns the accumulator into the domain DTO.
 *
 * @template C of object  cursor type
 * @template T            page item type
 * @template R            final DTO
 */
final class CursorAccumulator
{
    /**
     * @param callable():C        $openCursor   yields a cursor exposing hasMore()/next():iterable<T>
     * @param array<string,mixed> $initial      starting accumulator
     * @param callable(array<string,mixed>,T):void $reduce
     * @param callable(array<string,mixed>):R      $finalise
     * @return R
     */
    public function run(
        callable $openCursor,
        array $initial,
        callable $reduce,
        callable $finalise,
    ): mixed {
        $accumulator = $initial;
        $cursor = $openCursor();

        while ($cursor->hasMore()) {
            $page = $cursor->next();
            foreach ($page as $item) {
                $reduce($accumulator, $item);
            }
        }

        return $finalise($accumulator);
    }
}
