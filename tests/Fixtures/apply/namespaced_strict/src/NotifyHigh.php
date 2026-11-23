<?php
declare(strict_types=1);

namespace App\Ledger;

final class NotifyHigh
{
    public function notify(string $user, int $score): string
    {
        $tag = strtoupper($user);
        $line = render($tag, 'high');
        return $line;
    }

    public function keepMe(): string
    {
        return 'HIGH-KEPT';
    }
}
