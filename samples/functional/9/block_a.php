<?php
declare(strict_types=1);

namespace Acme\Chat\Language;

final class StopwordDetector
{
    /** @var array<string,list<string>> */
    private array $stopwords;

    public function __construct()
    {
        $this->stopwords = [
            'en' => ['the', 'and', 'is', 'in', 'it', 'of', 'to', 'a', 'that', 'with'],
            'es' => ['el', 'la', 'de', 'que', 'y', 'a', 'en', 'un', 'por', 'con'],
            'fr' => ['le', 'de', 'et', 'la', 'les', 'des', 'un', 'une', 'est', 'que'],
            'de' => ['der', 'die', 'und', 'das', 'ist', 'nicht', 'ich', 'sie', 'mit', 'auf'],
            'it' => ['il', 'la', 'di', 'che', 'e', 'a', 'in', 'un', 'per', 'con'],
            'pt' => ['o', 'a', 'de', 'que', 'e', 'do', 'da', 'em', 'um', 'para'],
        ];
    }

    public function detect(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $tokens = preg_split('/\W+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 2) {
            return null;
        }
        $scores = array_fill_keys(array_keys($this->stopwords), 0);
        foreach ($tokens as $token) {
            foreach ($this->stopwords as $lang => $words) {
                if (in_array($token, $words, true)) {
                    $scores[$lang]++;
                }
            }
        }
        arsort($scores);
        $best = (string) array_key_first($scores);
        $top  = $scores[$best];
        if ($top === 0) {
            return null;
        }
        $second = 0;
        foreach ($scores as $lang => $val) {
            if ($lang !== $best && $val > $second) {
                $second = $val;
            }
        }
        if ($top - $second < 1) {
            return null;
        }
        return $best;
    }
}
