<?php

namespace App\Services\Mapping;

final class CopyConfig
{
    public readonly bool $deepCopy;
    public readonly bool $skipNulls;

    public function __construct(bool $deepCopy = false, bool $skipNulls = true)
    {
        $this->deepCopy = $deepCopy;
        $this->skipNulls = $skipNulls;
    }
}

final class PropertyCopyService
{
    private CopyConfig $config;

    public function __construct(CopyConfig $config)
    {
        $this->config = $config;
    }

    public function copy(object $source, object $target, array $properties): object
    {
        foreach ($properties as $property) {
            if (!property_exists($source, $property)) {
                continue;
            }

            $value = $source->$property;

            if ($this->config->skipNulls && $value === null) {
                continue;
            }

            if ($this->config->deepCopy && (is_object($value) || is_array($value))) {
                $value = $this->deepClone($value);
            }

            $target->$property = $value;
        }

        return $target;
    }

    public function clone(object $source, array $properties): object
    {
        $clone = clone $source;

        return $this->copy($source, $clone, $properties);
    }

    public function mapToNew(object $source, string $targetClass, array $properties): object
    {
        $target = new $targetClass();

        return $this->copy($source, $target, $properties);
    }

    private function deepClone(mixed $value): mixed
    {
        if (is_object($value)) {
            return clone $value;
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $k => $v) {
                $result[$k] = $this->deepClone($v);
            }

            return $result;
        }

        return $value;
    }
}
