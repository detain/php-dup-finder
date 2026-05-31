<?php
declare(strict_types=1);

namespace App\Shipping\Labels;

use App\Storage\StorageInterface;
use App\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ShippingLabelUploader
{
    public function __construct(
        private StorageInterface $storage,
        private Connection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function upload(int $shipmentId, UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Invalid upload: ' . $file->getErrorMessage());
        }

        $size = $file->getSize();
        if ($size === null || $size === false) {
            throw new \InvalidArgumentException('Could not determine file size');
        }

        if ($size > 10485760) {
            throw new \DomainException('Label exceeds 10MiB limit');
        }

        $mime = $file->getMimeType();
        $allowed = ['application/pdf', 'image/png', 'image/jpeg'];
        if (!in_array($mime, $allowed, true)) {
            throw new \DomainException("Disallowed label MIME type: {$mime}");
        }

        $shipment = $this->db->fetchOne(
            'SELECT id, carrier, tracking_number, status FROM shipments WHERE id = ?',
            [$shipmentId]
        );

        if ($shipment === null) {
            throw new \RuntimeException('Shipment not found: ' . $shipmentId);
        }

        $extension = $file->guessExtension() ?? 'bin';
        $storageKey = sprintf('labels/%d/%s.%s', $shipmentId, bin2hex(random_bytes(8)), $extension);
        $this->storage->put($storageKey, file_get_contents($file->getPathname()));

        $this->db->execute(
            'UPDATE shipments SET label_path = ?, label_uploaded_at = NOW() WHERE id = ?',
            [$storageKey, $shipmentId]
        );

        $this->logger->info('Shipping label stored', [
            'shipment_id' => $shipmentId,
            'size_bytes'  => $size,
            'mime'        => $mime,
        ]);

        return $storageKey;
    }
}
