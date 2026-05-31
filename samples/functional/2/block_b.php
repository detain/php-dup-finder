<?php
declare(strict_types=1);

namespace Acme\Tax\Validation;

final class ChecksumVatValidator
{
    /** @var array<string,array{len:int,weights:int[],mod:int}> */
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            'AT' => ['len' => 9,  'weights' => [1,2,1,2,1,2,1],   'mod' => 10],
            'BE' => ['len' => 10, 'weights' => [1,1,1,1,1,1,1,1], 'mod' => 97],
            'DE' => ['len' => 9,  'weights' => [2,4,8,5,10,9,7,3],'mod' => 11],
            'NL' => ['len' => 12, 'weights' => [9,8,7,6,5,4,3,2], 'mod' => 11],
            'IT' => ['len' => 11, 'weights' => [1,2,1,2,1,2,1,2,1,2], 'mod' => 10],
            'ES' => ['len' => 9,  'weights' => [2,4,8,5,10,9,7,3], 'mod' => 23],
            'PT' => ['len' => 9,  'weights' => [9,8,7,6,5,4,3,2], 'mod' => 11],
        ];
    }

    public function check(string $candidate): bool
    {
        $normalized = strtoupper(str_replace([' ', '.', '-'], '', $candidate));
        if (strlen($normalized) < 4) {
            return false;
        }
        $country = substr($normalized, 0, 2);
        $rule    = $this->rules[$country] ?? null;
        if ($rule === null) {
            return false;
        }
        $body = substr($normalized, 2);
        if (strlen($body) !== $rule['len']) {
            return false;
        }
        if (!preg_match('/^[A-Z0-9]+$/', $body)) {
            return false;
        }
        $digits = preg_replace('/[^0-9]/', '', $body) ?? '';
        if (strlen($digits) < count($rule['weights'])) {
            return false;
        }
        $sum = 0;
        foreach ($rule['weights'] as $i => $w) {
            $sum += ((int) $digits[$i]) * $w;
        }
        $expected = $sum % $rule['mod'];
        $check    = (int) substr($digits, count($rule['weights']), 1);
        return $expected === $check || ($expected === 10 && $check === 0);
    }
}
