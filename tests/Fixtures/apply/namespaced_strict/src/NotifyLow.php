<?php
declare(strict_types=1);

namespace App\Ledger;

final class NotifyLow
{
    public function notify(string $user, int $score): string
    {
        $tag = strtoupper($user);
        $line = render($tag, 'low');
        return $line;
    }

    public function keepMe(): string
    {
        return 'LOW-KEPT';
    }
}
