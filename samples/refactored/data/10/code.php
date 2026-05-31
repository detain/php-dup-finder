<?php
declare(strict_types=1);

namespace App\Uploads;

final class UploadLimits
{
    public const MAX_BYTES = 10485760;

    public static function assertWithinLimit(int $size, string $kind): void
    {
        if ($size > self::MAX_BYTES) {
            throw new \DomainException(sprintf('%s exceeds %d bytes', $kind, self::MAX_BYTES));
        }
    }
}

namespace App\Shipping\Labels;

use App\Uploads\UploadLimits;
use App\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ShippingLabelUploader
{
    public function __construct(private StorageInterface $storage) {}

    public function upload(int $shipmentId, UploadedFile $file): string
    {
        UploadLimits::assertWithinLimit((int)$file->getSize(), 'Shipping label');
        $key = sprintf('labels/%d/%s', $shipmentId, bin2hex(random_bytes(8)));
        $this->storage->put($key, (string)file_get_contents($file->getPathname()));
        return $key;
    }
}

namespace App\Customs\Documents;

use App\Uploads\UploadLimits;
use App\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CustomsDocumentUploader
{
    public function __construct(private StorageInterface $storage) {}

    public function attachDocument(int $shipmentId, UploadedFile $file): string
    {
        UploadLimits::assertWithinLimit((int)$file->getSize(), 'Customs document');
        $key = sprintf('customs/%d/%s.pdf', $shipmentId, bin2hex(random_bytes(8)));
        $this->storage->put($key, (string)file_get_contents($file->getPathname()));
        return $key;
    }
}

namespace App\Freight\Damage;

use App\Uploads\UploadLimits;
use App\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FreightDamagePhotoService
{
    public function __construct(private StorageInterface $storage) {}

    public function attachPhoto(int $claimId, UploadedFile $photo): string
    {
        UploadLimits::assertWithinLimit((int)$photo->getSize(), 'Damage photo');
        $key = sprintf('damage-photos/%d/%s', $claimId, bin2hex(random_bytes(8)));
        $this->storage->put($key, (string)file_get_contents($photo->getPathname()));
        return $key;
    }
}
