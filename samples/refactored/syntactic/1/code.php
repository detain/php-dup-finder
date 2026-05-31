<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * Generic guarded-batch template. Callers provide:
 *  - resource open/close
 *  - per-item skip predicates
 *  - per-item work
 *  - error/finally hooks
 */
final class GuardedBatchRunner
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @template T
     * @template R
     * @param iterable<T>           $items
     * @param callable():R          $open
     * @param callable(R):void      $close
     * @param array<callable(T):?string> $skipReasons   each returns a reason string or null
     * @param callable(T,R):void    $work
     * @param string                $errorChannel
     */
    public function run(
        iterable $items,
        callable $open,
        callable $close,
        array $skipReasons,
        callable $work,
        string $errorChannel,
    ): int {
        $count = 0;
        $resource = $open();

        try {
            foreach ($items as $item) {
                foreach ($skipReasons as $reason) {
                    if (($why = $reason($item)) !== null) {
                        $this->logger->debug($why, ['item' => $item]);
                        continue 2;
                    }
                }
                $work($item, $resource);
                $count++;
            }
        } catch (\Throwable $error) {
            $this->logger->error($errorChannel, [
                'reason' => $error->getMessage(),
                'count'  => $count,
            ]);
            throw $error;
        } finally {
            $close($resource);
        }

        return $count;
    }
}
