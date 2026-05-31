<?php
declare(strict_types=1);

namespace Acme\Games\Scoring;

use Acme\Games\Domain\Player;

final class LeaderboardLister
{
    /**
     * @param array<string, Player> $players  player_id => Player
     * @return array<int, array{0:int,1:string}>
     */
    public function topByPoints(array $players): array
    {
        $rows = [];

        // canonical: foreach k=>v -> push tuple -> usort by [0]
        foreach ($players as $playerId => $player) {
            $rows[] = [$player->points(), $playerId . ':' . $player->displayName()];
        }
        usort($rows, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return $rows;
    }

    /**
     * @param array<string, Player> $players
     * @return array<int, array{0:int,1:string}>
     */
    public function topN(array $players, int $limit): array
    {
        return array_slice($this->topByPoints($players), 0, $limit);
    }
}
