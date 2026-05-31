<?php
declare(strict_types=1);

namespace Google\Cloud\Storage\Service;

use Google\Cloud\Storage\Repository\BucketRepository;
use Google\Cloud\Storage\Repository\ObjectRepository;
use Google\Cloud\Storage\Entity\Bucket;
use Google\Cloud\Storage\Entity\StorageObject;
use Google\Cloud\Storage\Entity\MultipartUpload;
use Google\Cloud\Storage\Exception\StorageException;
use Google\Cloud\Storage\Service\EncryptionService;
use Google\Cloud\Storage\Service\NotificationService;
use Psr\Log\LoggerInterface;

final class MultipartUploadService
{
    private BucketRepository $bucketRepository;
    private ObjectRepository $objectRepository;
    private EncryptionService $encryptionService;
    private NotificationService $notificationService;
    private LoggerInterface $logger;

    public function __construct(
        BucketRepository $bucketRepository,
        ObjectRepository $objectRepository,
        EncryptionService $encryptionService,
        NotificationService $notificationService,
        LoggerInterface $logger
    ) {
        $this->bucketRepository = $bucketRepository;
        $this->objectRepository = $objectRepository;
        $this->encryptionService = $encryptionService;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    public function initiateUpload(string $bucketName, string $objectName, string $contentType, int $size): UploadInitiationResult
    {
        $this->logger->info('Initiating multipart upload', [
            'bucket' => $bucketName,
            'object' => $objectName,
            'content_type' => $contentType,
            'size' => $size
        ]);

        $bucket = $this->bucketRepository->findByName($bucketName);
        if ($bucket === null) {
            throw new StorageException("Bucket not found: {$bucketName}");
        }

        if (!$bucket->isVersioningEnabled() && $this->objectRepository->exists($bucketName, $objectName)) {
            throw new StorageException("Object already exists and versioning is disabled: {$objectName}");
        }

        $uploadId = $this->generateUploadId();
        $encryptionKey = $this->encryptionService->generateCustomerManagedKey();

        $multipartUpload = MultipartUpload::create([
            'upload_id' => $uploadId,
            'bucket_name' => $bucketName,
            'object_name' => $objectName,
            'content_type' => $contentType,
            'size' => $size,
            'status' => 'initiated',
            'encryption_key_id' => $encryptionKey->getId(),
            'initiated_at' => new \DateTimeImmutable(),
            'expires_at' => (new \DateTimeImmutable())->modify('+7 days')
        ]);

        $savedUpload = $this->objectRepository->saveMultipartUpload($multipartUpload);

        $storageObject = StorageObject::create([
            'bucket' => $bucketName,
            'name' => $objectName,
            'content_type' => $contentType,
            'size' => $size,
            'upload_id' => $uploadId,
            'status' => 'incomplete',
            'created_at' => new \DateTimeImmutable()
        ]);

        $this->objectRepository->saveObjectMetadata($storageObject);

        $this->logger->info('Multipart upload initiated', [
            'upload_id' => $uploadId,
            'parts_expected' => ceil($size / $this->getPartSize())
        ]);

        return new UploadInitiationResult([
            'success' => true,
            'upload_id' => $uploadId,
            'object_name' => $objectName,
            'bucket' => $bucketName,
            'expires_at' => $multipartUpload->getExpiresAt()->format('c')
        ]);
    }

    public function completeUpload(string $uploadId, array $parts): CompleteUploadResult
    {
        $upload = $this->objectRepository->findMultipartUpload($uploadId);
        if ($upload === null) {
            throw new StorageException("Upload not found: {$uploadId}");
        }

        if ($upload->getStatus() !== 'initiated' && $upload->getStatus() !== 'in_progress') {
            throw new StorageException("Upload cannot be completed in status: {$upload->getStatus()}");
        }

        if (count($parts) === 0) {
            throw new StorageException('No parts provided for completion');
        }

        $this->objectRepository->updateUploadStatus($uploadId, 'finalizing');

        try {
            $assembledObject = $this->objectRepository->assembleObject($uploadId, $parts);

            $this->encryptionService->finalizeEncryption(
                $upload->getEncryptionKeyId(),
                $assembledObject->getId()
            );

            $finalMetadata = $this->objectRepository->finalizeObjectMetadata(
                $upload->getBucketName(),
                $upload->getObjectName(),
                [
                    'size' => $upload->getSize(),
                    'content_type' => $upload->getContentType(),
                    'finalized_at' => new \DateTimeImmutable(),
                    'parts_count' => count($parts),
                    'etag' => $assembledObject->getEtag()
                ]
            );

            $this->objectRepository->deleteMultipartUpload($uploadId);

            $this->notificationService->notifyObjectCreated(
                $upload->getBucketName(),
                $upload->getObjectName()
            );

            $this->logger->info('Multipart upload completed', [
                'upload_id' => $uploadId,
                'object' => $upload->getObjectName(),
                'parts_used' => count($parts)
            ]);

            return new CompleteUploadResult([
                'success' => true,
                'object_name' => $upload->getObjectName(),
                'bucket' => $upload->getBucketName(),
                'size' => $upload->getSize(),
                'etag' => $assembledObject->getEtag()
            ]);

        } catch (\Throwable $e) {
            $this->objectRepository->updateUploadStatus($uploadId, 'failed');
            $this->logger->error('Multipart upload completion failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function abortUpload(string $uploadId): AbortResult
    {
        $upload = $this->objectRepository->findMultipartUpload($uploadId);
        if ($upload === null) {
            throw new StorageException("Upload not found: {$uploadId}");
        }

        if ($upload->getStatus() === 'completed') {
            throw new StorageException('Cannot abort a completed upload');
        }

        $this->objectRepository->updateUploadStatus($uploadId, 'aborted');
        $this->objectRepository->deleteUploadedParts($uploadId);
        $this->objectRepository->deleteMultipartUpload($uploadId);

        $this->logger->info('Multipart upload aborted', [
            'upload_id' => $uploadId,
            'bucket' => $upload->getBucketName(),
            'object' => $upload->getObjectName()
        ]);

        return new AbortResult([
            'success' => true,
            'upload_id' => $uploadId
        ]);
    }

    private function generateUploadId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function getPartSize(): int
    {
        return 5 * 1024 * 1024;
    }
}
