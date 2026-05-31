<?php
declare(strict_types=1);

namespace Acme\Blog\Slugs;

final class TransliterateSlugger
{
    /** @var array<string,string> */
    private array $table;

    public function __construct()
    {
        $this->table = $this->buildTable();
    }

    public function build(string $name): string
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('name empty');
        }
        $codepoints = $this->utf8ToCodepoints($name);
        $out = '';
        foreach ($codepoints as $cp) {
            $ch = $this->table[$cp] ?? $this->fallback($cp);
            $out .= $ch;
        }
        $out = strtolower($out);
        $buffer = '';
        $last = '';
        for ($i = 0, $len = strlen($out); $i < $len; $i++) {
            $c = $out[$i];
            $isWord = ($c >= 'a' && $c <= 'z') || ($c >= '0' && $c <= '9');
            if ($isWord) {
                $buffer .= $c;
                $last = $c;
            } elseif ($last !== '-' && $buffer !== '') {
                $buffer .= '-';
                $last = '-';
            }
        }
        $buffer = trim($buffer, '-');
        $stop = ['the', 'a', 'an', 'of', 'and', 'or'];
        $segs = array_values(array_filter(explode('-', $buffer), static fn(string $s): bool => $s !== '' && !in_array($s, $stop, true)));
        if ($segs === []) {
            $segs = array_filter(explode('-', $buffer), static fn(string $s): bool => $s !== '');
        }
        $final = implode('-', $segs);
        return strlen($final) > 120 ? rtrim(substr($final, 0, 120), '-') : ($final !== '' ? $final : 'untitled');
    }

    /** @return array<int,string> */
    private function utf8ToCodepoints(string $s): array { $cps = []; $len = mb_strlen($s, 'UTF-8'); for ($i = 0; $i < $len; $i++) { $cps[] = mb_ord(mb_substr($s, $i, 1, 'UTF-8'), 'UTF-8'); } return $cps; }
    private function fallback(int $cp): string { return $cp < 128 ? chr($cp) : ' '; }
    /** @return array<int,string> */
    private function buildTable(): array { return [0xE9 => 'e', 0xE8 => 'e', 0xF1 => 'n', 0xDF => 'ss', 0xE6 => 'ae']; }
}
