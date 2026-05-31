<?php
declare(strict_types=1);

namespace App\Freight\Damage;

use App\Storage\StorageInterface;
use App\Database\Connection;
use App\Imaging\ImageOptimizer;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FreightDamagePhotoService
{
    public function __construct(
        private StorageInterface $storage,
        private Connection $db,
        private ImageOptimizer $optimizer,
    ) {
    }

    public function attachPhoto(int $claimId, UploadedFile $photo): string
    {
        $claim = $this->db->fetchOne(
            'SELECT id, shipment_id, status FROM damage_claims WHERE id = ?',
            [$claimId]
        );

        if ($claim === null) {
            throw new \RuntimeException("Claim not found: {$claimId}");
        }

        if ($claim['status'] === 'closed') {
            throw new \DomainException('Cannot add photos to a closed claim');
        }

        if (!$photo->isValid()) {
            throw new \InvalidArgumentException('Photo upload failed: ' . $photo->getErrorMessage());
        }

        $size = (int)$photo->getSize();
        if ($size > 10485760) {
            throw new \DomainException('Damage photo exceeds 10MiB limit');
        }

        $mime = (string)$photo->getMimeType();
        $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            throw new \DomainException("Photo must be png/jpeg/webp; got {$mime}");
        }

        $rawBytes = file_get_contents($photo->getPathname());
        if ($rawBytes === false) {
            throw new \RuntimeException('Could not read photo payload');
        }

        $optimized = $this->optimizer->compress($rawBytes, $mime);
        $extension = $photo->guessExtension() ?? 'jpg';
        $key = sprintf('damage-photos/%d/%s.%s', $claimId, bin2hex(random_bytes(8)), $extension);

        $this->storage->put($key, $optimized);

        $this->db->execute(
            'INSERT INTO damage_photos (claim_id, storage_key, original_size, optimized_size, mime, uploaded_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$claimId, $key, $size, strlen($optimized), $mime]
        );

        return $key;
    }
}
