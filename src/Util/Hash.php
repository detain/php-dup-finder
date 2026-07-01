<?php
declare(strict_types=1);

namespace Phpdup\Util;

final class Hash
{
    private const ALGO = 'xxh128';

    public static function of(string $input): string
    {
        return hash(self::ALGO, $input);
    }

    public static function ofMany(string ...$parts): string
    {
        return hash(self::ALGO, implode(Delimiters::HASH_JOIN, $parts));
    }
}
