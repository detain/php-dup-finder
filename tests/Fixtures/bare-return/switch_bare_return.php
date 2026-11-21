<?php
declare(strict_types=1);

/**
 * Regression substrate for improve_logic.md item 0.3 (BUG-A): single-
 * statement switch cases whose body is a bare `return;` used to fatal the
 * Normalizer's switch->match folding and poison forked PreprocessWorkers.
 * Dedicated corpus directory (like notify/sql/optional) so the item 0.4
 * smoke matrix can run the CLI over exactly these shapes; the golden suites
 * do not enumerate it and `bin/phpdup analyze tests/Fixtures` (CI smoke)
 * must now pass on it.
 */

final class BareReturnSwitch
{
    public static function stopOnEmpty(array $rows): int
    {
        switch (count($rows)) {
            case 0:
                return; // the historical crash shape — bare return, statement position
        }

        return count($rows);
    }

    public static function bailOnMarker(?string $value): int
    {
        switch ($value) {
            case '':
                return; // bare return against an empty string
            case null:
                return 0; // null-literal return — must NOT alias with the arm above
            default:
                return; // bare return on the default arm
        }
    }

    public function mixedArms(int $mode): int
    {
        switch ($mode) {
            case 1:
                return;                       // value-less arm
            case 2:
                return 2;                     // value arm — different encoding
            case 3:
                error_log('three');
                return;                       // multi-statement arm ending bare
        }

        return -1;
    }
}
