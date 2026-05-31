<?php
declare(strict_types=1);

namespace Acme\Polling;

final class PollResultAggregator
{
    public function __construct(private WeightTable $weights) {}

    /** @param array<int,BallotVote> $votes */
    public function aggregate(array $votes, string $pollId): PollResult
    {
        $reducer = function (array $carry, BallotVote $vote): array {
            $weight = $this->weights->forVoter($vote->voterId, $carry['poll']);
            $weighted = $vote->score * $weight;

            $carry['weighted'] += $weighted;
            $carry['raw']      += $vote->score;
            $carry['ballots']  += 1;
            return $carry;
        };

        $reducer = \Closure::bind($reducer, $this, self::class);

        $initial = [
            'weighted' => 0.0,
            'raw'      => 0,
            'ballots'  => 0,
            'poll'     => $pollId,
        ];

        $result = array_reduce($votes, $reducer, $initial);

        return new PollResult(
            raw:      $result['raw'],
            weighted: $result['weighted'],
            average:  $result['ballots'] > 0 ? $result['weighted'] / $result['ballots'] : 0.0,
            ballots:  $result['ballots'],
        );
    }
}
