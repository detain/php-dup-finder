<?php

declare(strict_types=1);

namespace App\Serialization;

final class ObjectPropertyMapper
{
    public function mapProperties(object $source, object $destination, array $props): object
    {
        foreach ($props as $prop) {
            if (property_exists($source, $prop)) {
                $destination->$prop = $source->$prop;
            }
        }

        return $destination;
    }

    public function mapAllProperties(object $source, object $destination): object
    {
        $vars = get_object_vars($source);

        foreach ($vars as $p => $v) {
            $destination->$p = $v;
        }

        return $destination;
    }

    public function mapWithPrefix(object $source, object $destination, string $prefix, array $props): object
    {
        foreach ($props as $prop) {
            $sourceProp = $prefix . ucfirst($prop);

            if (property_exists($source, $sourceProp)) {
                $destination->$prop = $source->$sourceProp;
            }
        }

        return $destination;
    }

    public function mapToArray(object $source, array $props): array
    {
        $output = [];

        foreach ($props as $prop) {
            if (property_exists($source, $prop)) {
                $output[$prop] = $source->$prop;
            }
        }

        return $output;
    }

    public function mapFromArray(array $source, object $destination, array $props): object
    {
        foreach ($props as $prop) {
            if (array_key_exists($prop, $source)) {
                $destination->$prop = $source[$prop];
            }
        }

        return $destination;
    }

    public function cloneObject(object $original, array $props): object
    {
        $clone = clone $original;

        return $this->mapProperties($original, $clone, $props);
    }

    public function cloneDeep(object $original, array $props): object
    {
        $clone = clone $original;

        foreach ($props as $prop) {
            if (property_exists($clone, $prop)) {
                $value = $clone->$prop;

                if (is_object($value)) {
                    $clone->$prop = clone $value;
                } elseif (is_array($value)) {
                    $clone->$prop = $this->cloneArrayRecursively($value);
                }
            }
        }

        return $clone;
    }

    public function mergeInto(object $source, object $destination, array $props): object
    {
        foreach ($props as $prop) {
            if (property_exists($source, $prop)) {
                $srcValue = $source->$prop;
                $dstValue = $destination->$prop;

                if (is_array($srcValue) && is_array($dstValue)) {
                    $destination->$prop = array_merge($dstValue, $srcValue);
                } else {
                    $destination->$prop = $srcValue;
                }
            }
        }

        return $destination;
    }

    public function copyNonNull(object $source, object $destination, array $props): object
    {
        foreach ($props as $prop) {
            if (property_exists($source, $prop)) {
                $value = $source->$prop;

                if ($value !== null) {
                    $destination->$prop = $value;
                }
            }
        }

        return $destination;
    }

    public function copyWithMapping(object $source, object $destination, array $propMap): object
    {
        foreach ($propMap as $srcProp => $dstProp) {
            if (property_exists($source, $srcProp)) {
                $destination->$dstProp = $source->$srcProp;
            }
        }

        return $destination;
    }

    public function copyExcluding(object $source, object $destination, array $excludedProps): object
    {
        $vars = get_object_vars($source);
        $excludedSet = array_flip($excludedProps);

        foreach ($vars as $p => $v) {
            if (!isset($excludedSet[$p])) {
                $destination->$p = $v;
            }
        }

        return $destination;
    }

    public function copyOnlyThese(object $source, object $destination, array $allowedProps): object
    {
        $allowedSet = array_flip($allowedProps);
        $vars = get_object_vars($source);

        foreach ($vars as $p => $v) {
            if (isset($allowedSet[$p])) {
                $destination->$p = $v;
            }
        }

        return $destination;
    }

    public function createAsCopyOf(object $source, string $targetClass, array $props): object
    {
        $target = new $targetClass();

        return $this->mapProperties($source, $target, $props);
    }

    public function createWithDefaults(object $source, string $targetClass, array $props, array $defaultVals): object
    {
        $target = new $targetClass();

        foreach ($defaultVals as $p => $val) {
            if (property_exists($target, $p)) {
                $target->$p = $val;
            }
        }

        return $this->mapProperties($source, $target, $props);
    }

    public function updateFrom(object $source, object $target, array $props): bool
    {
        $wasModified = false;

        foreach ($props as $p) {
            if (property_exists($source, $p) && property_exists($target, $p)) {
                if ($source->$p !== $target->$p) {
                    $target->$p = $source->$p;
                    $wasModified = true;
                }
            }
        }

        return $wasModified;
    }

    public function differences(object $a, object $b, array $props): array
    {
        $diffs = [];

        foreach ($props as $p) {
            if (property_exists($a, $p) && property_exists($b, $p)) {
                if ($a->$p !== $b->$p) {
                    $diffs[$p] = [
                        'original' => $a->$p,
                        'modified' => $b->$p,
                    ];
                }
            }
        }

        return $diffs;
    }

    public function sameValues(object $a, object $b, array $props): bool
    {
        foreach ($props as $p) {
            if (property_exists($a, $p) && property_exists($b, $p)) {
                if ($a->$p !== $b->$p) {
                    return false;
                }
            }
        }

        return true;
    }

    public function bind(object $target, array $data, array $fieldMap = []): object
    {
        if (empty($fieldMap)) {
            $fieldMap = array_keys($data);
        }

        foreach ($fieldMap as $srcField => $tgtField) {
            if (is_numeric($srcField)) {
                $srcField = $tgtField;
            }

            if (array_key_exists($srcField, $data)) {
                $target->$tgtField = $data[$srcField];
            }
        }

        return $target;
    }

    public function export(object $source, array $props = []): array
    {
        if (empty($props)) {
            return get_object_vars($source);
        }

        $data = [];

        foreach ($props as $p) {
            if (property_exists($source, $p)) {
                $data[$p] = $source->$p;
            }
        }

        return $data;
    }

    public function exportPrefixed(object $source, string $prefix, array $props): array
    {
        $data = [];

        foreach ($props as $p) {
            $fullProp = $prefix . ucfirst($p);

            if (property_exists($source, $fullProp)) {
                $data[$p] = $source->$fullProp;
            }
        }

        return $data;
    }

    private function cloneArrayRecursively(array $arr): array
    {
        $result = [];

        foreach ($arr as $k => $v) {
            if (is_object($v)) {
                $result[$k] = clone $v;
            } elseif (is_array($v)) {
                $result[$k] = $this->cloneArrayRecursively($v);
            } else {
                $result[$k] = $v;
            }
        }

        return $result;
    }
}
