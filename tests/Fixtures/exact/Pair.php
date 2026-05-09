<?php
namespace Fixtures\Exact;

class Pair
{
    public function loopOne(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            if ($item['active']) {
                $total += $item['amount'];
            }
        }
        return $total;
    }

    public function loopTwo(array $items): int
    {
        $sum = 0;
        foreach ($items as $entry) {
            if ($entry['active']) {
                $sum += $entry['amount'];
            }
        }
        return $sum;
    }
}
