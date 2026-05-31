<?php

declare(strict_types=1);

namespace App\Mapping;

final class PropertyCopier
{
    public function copy(object $source, object $target, array $properties): object
    {
        foreach ($properties as $property) {
            if (property_exists($source, $property)) {
                $target->$property = $source->$property;
            }
        }

        return $target;
    }

    public function copyAll(object $source, object $target): object
    {
        $sourceProps = get_object_vars($source);

        foreach ($sourceProps as $property => $value) {
            $target->$property = $value;
        }

        return $target;
    }

    public function copyWithPrefix(object $source, object $target, string $prefix, array $properties): object
    {
        foreach ($properties as $property) {
            $sourceProperty = $prefix . ucfirst($property);

            if (property_exists($source, $sourceProperty)) {
                $target->$property = $source->$sourceProperty;
            }
        }

        return $target;
    }

    public function copyToArray(object $source, array $properties): array
    {
        $result = [];

        foreach ($properties as $property) {
            if (property_exists($source, $property)) {
                $result[$property] = $source->$property;
            }
        }

        return $result;
    }

    public function copyFromArray(array $source, object $target, array $properties): object
    {
        foreach ($properties as $property) {
            if (array_key_exists($property, $source)) {
                $target->$property = $source[$property];
            }
        }

        return $target;
    }

    public function clone(object $source, array $properties): object
    {
        $clone = clone $source;

        return $this->copy($source, $clone, $properties);
    }

    public function deepClone(object $source, array $properties): object
    {
        $clone = clone $source;

        foreach ($properties as $property) {
            if (property_exists($clone, $property)) {
                $value = $clone->$property;

                if (is_object($value)) {
                    $clone->$property = clone $value;
                } elseif (is_array($value)) {
                    $clone->$property = $this->deepCloneArray($value);
                }
            }
        }

        return $clone;
    }

    public function merge(object $source, object $target, array $properties): object
    {
        foreach ($properties as $property) {
            if (property_exists($source, $property)) {
                $sourceValue = $source->$property;
                $targetValue = $target->$property;

                if (is_array($sourceValue) && is_array($targetValue)) {
                    $target->$property = array_merge($targetValue, $sourceValue);
                } else {
                    $target->$property = $sourceValue;
                }
            }
        }

        return $target;
    }

    public function copyIfNotNull(object $source, object $target, array $properties): object
    {
        foreach ($properties as $property) {
            if (property_exists($source, $property)) {
                $value = $source->$property;

                if ($value !== null) {
                    $target->$property = $value;
                }
            }
        }

        return $target;
    }

    public function copyMatching(object $source, object $target, array $propertyMap): object
    {
        foreach ($propertyMap as $sourceProperty => $targetProperty) {
            if (property_exists($source, $sourceProperty)) {
                $target->$targetProperty = $source->$sourceProperty;
            }
        }

        return $target;
    }

    public function copyExclude(object $source, object $target, array $excludedProperties): object
    {
        $sourceProps = get_object_vars($source);
        $excludedSet = array_flip($excludedProperties);

        foreach ($sourceProps as $property => $value) {
            if (!isset($excludedSet[$property])) {
                $target->$property = $value;
            }
        }

        return $target;
    }

    public function copyOnly(object $source, object $target, array $allowedProperties): object
    {
        $allowedSet = array_flip($allowedProperties);
        $sourceProps = get_object_vars($source);

        foreach ($sourceProps as $property => $value) {
            if (isset($allowedSet[$property])) {
                $target->$property = $value;
            }
        }

        return $target;
    }

    public function mapToNew(object $source, string $targetClass, array $properties): object
    {
        $target = new $targetClass();

        return $this->copy($source, $target, $properties);
    }

    public function mapToNewWithDefaults(object $source, string $targetClass, array $properties, array $defaults): object
    {
        $target = new $targetClass();

        foreach ($defaults as $property => $value) {
            if (property_exists($target, $property)) {
                $target->$property = $value;
            }
        }

        return $this->copy($source, $target, $properties);
    }

    public function sync(object $source, object $target, array $properties): bool
    {
        $changed = false;

        foreach ($properties as $property) {
            if (property_exists($source, $property) && property_exists($target, $property)) {
                if ($source->$property !== $target->$property) {
                    $target->$property = $source->$property;
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    public function diff(object $a, object $b, array $properties): array
    {
        $differences = [];

        foreach ($properties as $property) {
            if (property_exists($a, $property) && property_exists($b, $property)) {
                if ($a->$property !== $b->$property) {
                    $differences[$property] = [
                        'from' => $a->$property,
                        'to' => $b->$property,
                    ];
                }
            }
        }

        return $differences;
    }

    public function equals(object $a, object $b, array $properties): bool
    {
        foreach ($properties as $property) {
            if (property_exists($a, $property) && property_exists($b, $property)) {
                if ($a->$property !== $b->$property) {
                    return false;
                }
            }
        }

        return true;
    }

    public function hydrate(object $target, array $data, array $propertyMap = []): object
    {
        if (empty($propertyMap)) {
            $propertyMap = array_keys($data);
        }

        foreach ($propertyMap as $sourceKey => $targetProperty) {
            if (is_numeric($sourceKey)) {
                $sourceKey = $targetProperty;
            }

            if (array_key_exists($sourceKey, $data)) {
                $target->$targetProperty = $data[$sourceKey];
            }
        }

        return $target;
    }

    public function extract(object $source, array $properties = []): array
    {
        if (empty($properties)) {
            return get_object_vars($source);
        }

        $data = [];

        foreach ($properties as $property) {
            if (property_exists($source, $property)) {
                $data[$property] = $source->$property;
            }
        }

        return $data;
    }

    public function extractWithPrefix(object $source, string $prefix, array $properties): array
    {
        $data = [];

        foreach ($properties as $property) {
            $fullProperty = $prefix . ucfirst($property);

            if (property_exists($source, $fullProperty)) {
                $data[$property] = $source->$fullProperty;
            }
        }

        return $data;
    }

    private function deepCloneArray(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_object($value)) {
                $result[$key] = clone $value;
            } elseif (is_array($value)) {
                $result[$key] = $this->deepCloneArray($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
