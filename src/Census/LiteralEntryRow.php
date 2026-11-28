<?php
declare(strict_types=1);

namespace Phpdup\Census;

/**
 * One reported literal-census entry (item 3.3): the bucket, the group
 * hash, a bounded human sample, the role tally (only the 'const'
 * bucket varies: decl/enum_case/use counts), corpus support, and
 * canonical location samples ('file:line').
 */
final class LiteralEntryRow
{
    /**
     * @param array<string,int> $roles role => occurrence count ("" for every non-const bucket)
     * @param list<string>      $locations
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $hash,
        public readonly string $sample,
        public readonly array $roles,
        public readonly int $sites,
        public readonly int $files,
        public readonly array $locations,
    ) {
    }
}
