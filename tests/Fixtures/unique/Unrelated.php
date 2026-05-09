<?php
namespace Fixtures\Unique;

class Unrelated
{
    public function complicatedThing(array $matrix): array
    {
        $rows = count($matrix);
        $cols = $rows ? count($matrix[0]) : 0;
        $transposed = [];
        for ($c = 0; $c < $cols; $c++) {
            $row = [];
            for ($r = 0; $r < $rows; $r++) {
                $row[] = $matrix[$r][$c] ?? null;
            }
            $transposed[] = $row;
        }
        return $transposed;
    }
}
