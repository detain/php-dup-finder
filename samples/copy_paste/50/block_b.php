<?php

declare(strict_types=1);

namespace App\Models;

final class EntityPropertyTransfer
{
    public function transfer(object $from, object $to, array $fields): object
    {
        foreach ($fields as $field) {
            if (property_exists($from, $field)) {
                $to->$field = $from->$field;
            }
        }

        return $to;
    }

    public function transferAll(object $from, object $to): object
    {
        $vars = get_object_vars($from);

        foreach ($vars as $prop => $val) {
            $to->$prop = $val;
        }

        return $to;
    }

    public function transferPrefixed(object $from, object $to, string $prefix, array $fields): object
    {
        foreach ($fields as $field) {
            $sourceField = $prefix . ucfirst($field);

            if (property_exists($from, $sourceField)) {
                $to->$field = $from->$sourceField;
            }
        }

        return $to;
    }

    public function transferToArray(object $from, array $fields): array
    {
        $output = [];

        foreach ($fields as $field) {
            if (property_exists($from, $field)) {
                $output[$field] = $from->$field;
            }
        }

        return $output;
    }

    public function transferFromArray(array $from, object $to, array $fields): object
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $from)) {
                $to->$field = $from[$field];
            }
        }

        return $to;
    }

    public function duplicate(object $source, array $fields): object
    {
        $copy = clone $source;

        return $this->transfer($source, $copy, $fields);
    }

    public function duplicateDeep(object $source, array $fields): object
    {
        $copy = clone $source;

        foreach ($fields as $field) {
            if (property_exists($copy, $field)) {
                $val = $copy->$field;

                if (is_object($val)) {
                    $copy->$field = clone $val;
                } elseif (is_array($val)) {
                    $copy->$field = $this->recursiveCloneArray($val);
                }
            }
        }

        return $copy;
    }

    public function mergeProperties(object $from, object $to, array $fields): object
    {
        foreach ($fields as $field) {
            if (property_exists($from, $field)) {
                $fromVal = $from->$field;
                $toVal = $to->$field;

                if (is_array($fromVal) && is_array($toVal)) {
                    $to->$field = array_merge($toVal, $fromVal);
                } else {
                    $to->$field = $fromVal;
                }
            }
        }

        return $to;
    }

    public function transferWhenSet(object $from, object $to, array $fields): object
    {
        foreach ($fields as $field) {
            if (property_exists($from, $field)) {
                $val = $from->$field;

                if ($val !== null) {
                    $to->$field = $val;
                }
            }
        }

        return $to;
    }

    public function transferMapped(object $from, object $to, array $mapping): object
    {
        foreach ($mapping as $srcField => $tgtField) {
            if (property_exists($from, $srcField)) {
                $to->$tgtField = $from->$srcField;
            }
        }

        return $to;
    }

    public function transferExcept(object $from, object $to, array $exceptFields): object
    {
        $vars = get_object_vars($from);
        $exceptSet = array_flip($exceptFields);

        foreach ($vars as $prop => $val) {
            if (!isset($exceptSet[$prop])) {
                $to->$prop = $val;
            }
        }

        return $to;
    }

    public function transferOnly(object $from, object $to, array $allowedFields): object
    {
        $allowedSet = array_flip($allowedFields);
        $vars = get_object_vars($from);

        foreach ($vars as $prop => $val) {
            if (isset($allowedSet[$prop])) {
                $to->$prop = $val;
            }
        }

        return $to;
    }

    public function instantiateWith(object $source, string $className, array $fields): object
    {
        $instance = new $className();

        return $this->transfer($source, $instance, $fields);
    }

    public function instantiateWithDefaults(object $source, string $className, array $fields, array $defaults): object
    {
        $instance = new $className();

        foreach ($defaults as $prop => $defaultVal) {
            if (property_exists($instance, $prop)) {
                $instance->$prop = $defaultVal;
            }
        }

        return $this->transfer($source, $instance, $fields);
    }

    public function refresh(object $source, object $target, array $fields): bool
    {
        $hasChanges = false;

        foreach ($fields as $field) {
            if (property_exists($source, $field) && property_exists($target, $field)) {
                if ($source->$field !== $target->$field) {
                    $target->$field = $source->$field;
                    $hasChanges = true;
                }
            }
        }

        return $hasChanges;
    }

    public function computeDiff(object $a, object $b, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            if (property_exists($a, $field) && property_exists($b, $field)) {
                if ($a->$field !== $b->$field) {
                    $changes[$field] = [
                        'before' => $a->$field,
                        'after' => $b->$field,
                    ];
                }
            }
        }

        return $changes;
    }

    public function areEqual(object $a, object $b, array $fields): bool
    {
        foreach ($fields as $field) {
            if (property_exists($a, $field) && property_exists($b, $field)) {
                if ($a->$field !== $b->$field) {
                    return false;
                }
            }
        }

        return true;
    }

    public function populate(object $target, array $data, array $map = []): object
    {
        if (empty($map)) {
            $map = array_keys($data);
        }

        foreach ($map as $srcKey => $tgtField) {
            if (is_numeric($srcKey)) {
                $srcKey = $tgtField;
            }

            if (array_key_exists($srcKey, $data)) {
                $target->$tgtField = $data[$srcKey];
            }
        }

        return $target;
    }

    public function export(object $source, array $fields = []): array
    {
        if (empty($fields)) {
            return get_object_vars($source);
        }

        $data = [];

        foreach ($fields as $field) {
            if (property_exists($source, $field)) {
                $data[$field] = $source->$field;
            }
        }

        return $data;
    }

    public function exportPrefixed(object $source, string $prefix, array $fields): array
    {
        $data = [];

        foreach ($fields as $field) {
            $fullField = $prefix . ucfirst($field);

            if (property_exists($source, $fullField)) {
                $data[$field] = $source->$fullField;
            }
        }

        return $data;
    }

    private function recursiveCloneArray(array $arr): array
    {
        $result = [];

        foreach ($arr as $k => $v) {
            if (is_object($v)) {
                $result[$k] = clone $v;
            } elseif (is_array($v)) {
                $result[$k] = $this->recursiveCloneArray($v);
            } else {
                $result[$k] = $v;
            }
        }

        return $result;
    }
}
