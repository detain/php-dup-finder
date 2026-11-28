<?php
declare(strict_types=1);

// tests/Fixtures/class-shape/tp_a.php — template-method half a: same
// shape AND member names as tp_b.php; label() differs only in its string
// literal, drain() is byte-equal. The class-shape fold blanks the
// literal (skeleton exact), while the raw strict lane still sees the two
// spellings -> label() verdict 'parameterizable', drain() 'identical-in-all'.

final class ImportJobAlpha
{
    public function label(): string
    {
        return 'alpha-import';
    }

    public function drain(): int
    {
        return 7;
    }
}
