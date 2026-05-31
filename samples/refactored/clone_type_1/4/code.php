<?php
declare(strict_types=1);

namespace Acme\Reports\Support;

final class DateRangeBuilder
{
    /**
     * Build a normalized inclusive date range with bucket label.
     *
     * @return array{from:string,to:string,days:int,bucket:string}
     */
    public static function build(string $start, string $end): array
    {
        $fromTs = strtotime($start . ' 00:00:00');
        $toTs = strtotime($end . ' 23:59:59');
        if ($fromTs === false || $toTs === false) {
            return ['from' => '', 'to' => '', 'days' => 0, 'bucket' => 'invalid'];
        }
        if ($fromTs > $toTs) {
            [$fromTs, $toTs] = [$toTs, $fromTs];
        }
        $days = (int) floor(($toTs - $fromTs) / 86400) + 1;
        $bucket = $days <= 7 ? 'week' : ($days <= 31 ? 'month' : 'long');
        $from = date('Y-m-d H:i:s', $fromTs);
        $to = date('Y-m-d H:i:s', $toTs);
        return ['from' => $from, 'to' => $to, 'days' => $days, 'bucket' => $bucket];
    }
}

// Query builders call DateRangeBuilder::build($start, $end) and pass the result into their sql().
