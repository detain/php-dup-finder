<?php
declare(strict_types=1);

namespace Acme\Ops;

final class ServerUtilisationAggregator
{
    public function __construct(private CapacityTable $capacity) {}

    /** @param array<int,SampleRow> $samples */
    public function aggregate(array $samples, string $clusterId): UtilisationReport
    {
        $reducer = function (array $carry, SampleRow $sample): array {
            $cap = $this->capacity->lookup($carry['cluster']);
            $usage = $sample->cpuTicks / max(1, $cap);

            $carry['ticks']  += $sample->cpuTicks;
            $carry['load']   += $usage;
            $carry['count']  += 1;
            return $carry;
        };

        $reducer = \Closure::bind($reducer, $this, self::class);

        $initial = [
            'ticks'   => 0,
            'load'    => 0.0,
            'count'   => 0,
            'cluster' => $clusterId,
        ];

        $result = array_reduce($samples, $reducer, $initial);

        return new UtilisationReport(
            ticks:    $result['ticks'],
            load:     $result['load'],
            avgLoad:  $result['count'] > 0 ? $result['load'] / $result['count'] : 0.0,
            samples:  $result['count'],
        );
    }
}
