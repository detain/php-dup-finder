<?php
declare(strict_types=1);

namespace App\Customs\Documents;

use App\Storage\StorageInterface;
use App\Database\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CustomsDocumentUploader
{
    public function __construct(
        private StorageInterface $storage,
        private Connection $db,
        private ValidatorInterface $validator,
    ) {
    }

    public function attachDocument(int $shipmentId, string $docType, UploadedFile $file): array
    {
        $allowedTypes = ['commercial_invoice', 'packing_list', 'cert_of_origin', 'bill_of_lading'];
        if (!in_array($docType, $allowedTypes, true)) {
            throw new \InvalidArgumentException("Unknown customs document type: {$docType}");
        }

        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Upload error: ' . $file->getError());
        }

        $size = (int)$file->getSize();
        if ($size <= 0) {
            throw new \InvalidArgumentException('Empty file');
        }

        if ($size > 10485760) {
            throw new \DomainException('Customs document exceeds 10MiB limit');
        }

        $mime = $file->getMimeType();
        if ($mime !== 'application/pdf') {
            throw new \DomainException('Customs docs must be PDF; got ' . (string)$mime);
        }

        $shipment = $this->db->fetchOne(
            'SELECT id, destination_country, status FROM shipments WHERE id = ?',
            [$shipmentId]
        );
        if ($shipment === null) {
            throw new \RuntimeException('Shipment not found');
        }

        if ($shipment['status'] === 'delivered') {
            throw new \DomainException('Cannot attach customs doc to delivered shipment');
        }

        $key = sprintf('customs/%s/%d/%s.pdf', $docType, $shipmentId, bin2hex(random_bytes(8)));
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) {
            throw new \RuntimeException('Could not read upload payload');
        }

        $this->storage->put($key, $bytes);

        $this->db->execute(
            'INSERT INTO customs_documents (shipment_id, doc_type, storage_key, size_bytes, uploaded_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$shipmentId, $docType, $key, $size]
        );

        return ['key' => $key, 'size' => $size];
    }
}
