<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * Reusable invariant helpers. Value objects now declare promoted properties and
 * call a single line of guards in the constructor instead of three near-identical
 * if-throw cascades each.
 */
final class Invariants
{
    public static function inRange(
        string $name,
        int|float $value,
        int|float $min,
        int|float $max,
    ): void {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(
                sprintf('%s %s out of range [%s, %s]', $name, (string) $value, (string) $min, (string) $max),
            );
        }
    }

    public static function nonEmpty(string $name, string $value): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException($name . ' must not be empty');
        }
    }

    public static function exactLength(string $name, string $value, int $len): void
    {
        if (strlen($value) !== $len) {
            throw new \InvalidArgumentException(
                sprintf('%s must be exactly %d chars', $name, $len),
            );
        }
    }
}

/* Example usage in Coordinate:
 *
 *  public function __construct(
 *      public readonly float  $latitude,
 *      public readonly float  $longitude,
 *      public readonly float  $altitudeMeters,
 *      public readonly string $datum,
 *  ) {
 *      Invariants::inRange('latitude',  $this->latitude,       -90,    90);
 *      Invariants::inRange('longitude', $this->longitude,     -180,   180);
 *      Invariants::inRange('altitude',  $this->altitudeMeters, -500, 10_000);
 *      Invariants::nonEmpty('datum',    $this->datum);
 *  }
 */
