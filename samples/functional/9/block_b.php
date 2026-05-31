<?php
declare(strict_types=1);

namespace Acme\Chat\Language;

final class NgramCosineDetector
{
    /** @var array<string,array<string,float>> */
    private array $profiles;

    /** @param array<string,array<string,float>> $profiles */
    public function __construct(array $profiles)
    {
        if ($profiles === []) {
            throw new \InvalidArgumentException('profiles empty');
        }
        $this->profiles = $profiles;
    }

    public function classify(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        if (mb_strlen($normalized, 'UTF-8') < 6) {
            return null;
        }
        $sample = $this->buildProfile($normalized);
        if ($sample === []) {
            return null;
        }
        $best   = null;
        $bestSim = -1.0;
        foreach ($this->profiles as $lang => $profile) {
            $sim = $this->cosine($sample, $profile);
            if ($sim > $bestSim) {
                $bestSim = $sim;
                $best    = $lang;
            }
        }
        if ($bestSim < 0.25) {
            return null;
        }
        return $best;
    }

    /** @return array<string,float> */
    private function buildProfile(string $text): array
    {
        $padded = '  ' . $text . '  ';
        $len    = mb_strlen($padded, 'UTF-8');
        $counts = [];
        for ($i = 0; $i < $len - 2; $i++) {
            $gram = mb_substr($padded, $i, 3, 'UTF-8');
            $counts[$gram] = ($counts[$gram] ?? 0) + 1;
        }
        $total = array_sum($counts);
        if ($total === 0) {
            return [];
        }
        foreach ($counts as $g => $c) {
            $counts[$g] = $c / $total;
        }
        return $counts;
    }

    /**
     * @param array<string,float> $a
     * @param array<string,float> $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $k => $v) { if (isset($b[$k])) { $dot += $v * $b[$k]; } }
        $ma = sqrt(array_sum(array_map(static fn($v) => $v * $v, $a)));
        $mb = sqrt(array_sum(array_map(static fn($v) => $v * $v, $b)));
        return ($ma === 0.0 || $mb === 0.0) ? 0.0 : $dot / ($ma * $mb);
    }
}
