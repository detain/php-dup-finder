<?php

namespace App\Services\Utilities;

final class MergeConfig
{
    public readonly bool $recursive;
    public readonly bool $preserveNumeric;

    public function __construct(bool $recursive = true, bool $preserveNumeric = false)
    {
        $this->recursive = $recursive;
        $this->preserveNumeric = $preserveNumeric;
    }
}

final class ArrayMergeService
{
    private MergeConfig $config;

    public function __construct(MergeConfig $config)
    {
        $this->config = $config;
    }

    public function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                if ($this->config->preserveNumeric && !$this->isAssociative($value) && !$this->isAssociative($base[$key])) {
                    $base[$key] = array_merge($base[$key], $value);
                } elseif ($this->config->recursive) {
                    $base[$key] = $this->merge($base[$key], $value);
                } else {
                    $base[$key] = $value;
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public function mergeAll(array ...$arrays): array
    {
        $result = [];

        foreach ($arrays as $array) {
            $result = $this->merge($result, $array);
        }

        return $result;
    }

    private function isAssociative(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
