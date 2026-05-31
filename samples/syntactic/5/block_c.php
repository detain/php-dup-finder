<?php
declare(strict_types=1);

namespace Acme\Color;

final class RgbColor
{
    public function __construct(
        public readonly int $red,
        public readonly int $green,
        public readonly int $blue,
        public readonly string $colorSpace,
    ) {
        if ($this->red < 0 || $this->red > 255) {
            throw new \InvalidArgumentException(
                sprintf('red %d out of range [0, 255]', $this->red),
            );
        }

        if ($this->green < 0 || $this->green > 255) {
            throw new \InvalidArgumentException(
                sprintf('green %d out of range [0, 255]', $this->green),
            );
        }

        if ($this->blue < 0 || $this->blue > 255) {
            throw new \InvalidArgumentException(
                sprintf('blue %d out of range [0, 255]', $this->blue),
            );
        }

        if ($this->colorSpace === '') {
            throw new \InvalidArgumentException('colorSpace must not be empty');
        }
    }

    public function asArray(): array
    {
        return [
            'r'     => $this->red,
            'g'     => $this->green,
            'b'     => $this->blue,
            'space' => $this->colorSpace,
        ];
    }
}
