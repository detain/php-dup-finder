<?php
declare(strict_types=1);

namespace Acme\Reports\Orders;

final class OrderQueryBuilder
{
    /**
     * Build a closed date range for the given calendar window.
     *
     * @param string $start ISO start date
     * @param string $end   ISO end date
     * @return array{from:string,to:string,days:int,bucket:string}
     */
    public function buildRange(string $start, string $end): array
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

    public function sql(array $range): string
    {
        return "SELECT id FROM orders WHERE created_at BETWEEN '{$range['from']}' AND '{$range['to']}'";
    }
}
