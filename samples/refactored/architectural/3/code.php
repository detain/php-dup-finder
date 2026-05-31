<?php
declare(strict_types=1);

namespace App\Reads;

/**
 * @template Q of object
 * @template P of object
 */
interface QueryReader
{
    /** @param Q $query @return P */
    public function read(object $query): object;

    /** @param Q $query */
    public function cacheKey(object $query): string;
}

/** @template Q of object @template P of object */
final class CachingQueryBus
{
    /** @var array<class-string, QueryReader<object, object>> */
    private array $readers;

    /** @var array<string, object> */
    private array $cache = [];

    /** @param array<class-string, QueryReader<object, object>> $readers */
    public function __construct(array $readers)
    {
        $this->readers = $readers;
    }

    public function ask(object $query): object
    {
        $reader = $this->readers[$query::class]
            ?? throw new \InvalidArgumentException('No reader for ' . $query::class);
        $key = $query::class . '|' . $reader->cacheKey($query);
        return $this->cache[$key] ??= $reader->read($query);
    }
}

/**
 * Each read-side now only provides a Query DTO, a Projection DTO, and one Reader
 * implementing QueryReader. Caching, dispatch, and key handling are framework-level.
 */
