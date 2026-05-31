<?php
declare(strict_types=1);

namespace Acme\Common\Resilience;

/**
 * Run an operation with a canonical 3-tier error mapping plus a finalizer.
 *
 * @template TOk
 * @template TNet of \Throwable
 * @template TTimeout of \Throwable
 */
final class TieredErrorBoundary
{
    /**
     * @param class-string<TNet>     $networkClass
     * @param class-string<TTimeout> $timeoutClass
     * @param callable(): TOk        $op
     * @param callable(): TOk        $onNetwork
     * @param callable(): TOk        $onTimeout
     * @param callable(): TOk        $onUnknown
     * @param callable(): void       $finally
     * @return TOk
     */
    public static function run(
        string $networkClass,
        string $timeoutClass,
        callable $op,
        callable $onNetwork,
        callable $onTimeout,
        callable $onUnknown,
        callable $finally,
    ): mixed {
        try {
            return $op();
        } catch (\Throwable $e) {
            if ($e instanceof $networkClass) {
                return $onNetwork();
            }
            if ($e instanceof $timeoutClass) {
                return $onTimeout();
            }
            return $onUnknown();
        } finally {
            $finally();
        }
    }
}
