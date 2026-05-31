<?php
declare(strict_types=1);

namespace Acme\Media\Imaging;

use Acme\Media\Domain\Asset;
use Acme\Media\Domain\Thumbnail;

final class ThumbnailTransformer
{
    public function __construct(
        private readonly int $width,
        private readonly int $height,
    ) {
    }

    /**
     * @param array<int, Asset> $assets
     * @return array<int, Thumbnail>
     */
    public function build(array $assets): array
    {
        // same lexeme stream: array_values(array_filter(array_map(...)))
        return array_values(array_filter(array_map(
            function (Asset $asset): ?Thumbnail {
                if (!$asset->isImage()) {
                    return null;
                }
                return new Thumbnail(
                    $asset->id(),
                    $asset->path(),
                    $this->width,
                    $this->height,
                );
            },
            $assets,
        ), static fn (?Thumbnail $t): bool => $t !== null));
    }

    /**
     * @param array<int, Asset> $assets
     */
    public function imageCount(array $assets): int
    {
        return count($this->build($assets));
    }
}
