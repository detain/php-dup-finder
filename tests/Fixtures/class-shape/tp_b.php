<?php
declare(strict_types=1);

// tests/Fixtures/class-shape/tp_b.php — template-method half b (see tp_a.php).

final class ImportJobBeta
{
    public function label(): string
    {
        return 'beta-import';
    }

    public function drain(): int
    {
        return 7;
    }
}
