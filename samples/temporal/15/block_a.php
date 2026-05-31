<?php
declare(strict_types=1);

namespace Cloudflare\Stream\Service;

use Cloudflare\Stream\Repository\VideoRepository;
use Cloudflare\Stream\Repository\TranscodeJobRepository;
use Cloudflare\Stream\Repository\DeliveryRepository;
use Cloudflare\Stream\Entity\Video;
use Cloudflare\Stream\Entity\TranscodeProfile;
use Cloudflare\Stream\Entity\TranscodeJob;
use Cloudflare\Stream\Exception\StreamException;
use Cloudflare\Stream\Service\MediaProcessingService;
use Cloudflare\Stream\Service\CDNInvalidationService;
use Psr\Log\LoggerInterface;

final class VideoUploadService
{
    private VideoRepository $videoRepository;
    private TranscodeJobRepository $transcodeRepo;
    private DeliveryRepository $deliveryRepo;
    private MediaProcessingService $mediaProcessor;
    private CDNInvalidationService $cdnInvalidator;
    private LoggerInterface $logger;

    public function __construct(
        VideoRepository $videoRepository,
        TranscodeJobRepository $transcodeRepo,
        DeliveryRepository $deliveryRepo,
        MediaProcessingService $mediaProcessor,
        CDNInvalidationService $cdnInvalidator,
        LoggerInterface $logger
    ) {
        $this->videoRepository = $videoRepository;
        $this->transcodeRepo = $transcodeRepo;
        $this->deliveryRepo = $deliveryRepo;
        $this->mediaProcessor = $mediaProcessor;
        $this->cdnInvalidator = $cdnInvalidator;
        $this->logger = $logger;
    }

    public function uploadVideo(string $accountId, array $videoData): VideoUploadResult
    {
        $this->logger->info('Starting video upload', [
            'account_id' => $accountId,
            'original_filename' => $videoData['filename']
        ]);

        $account = $this->videoRepository->findAccount($accountId);
        if ($account === null) {
            throw new StreamException("Account not found: {$accountId}");
        }

        $uploadToken = $this->videoRepository->generateUploadToken();
        if ($uploadToken === null) {
            throw new StreamException('Failed to generate upload token');
        }

        $video = Video::create([
            'account_id' => $accountId,
            'original_filename' => $videoData['filename'],
            'mime_type' => $videoData['mime_type'],
            'size_bytes' => $videoData['size'],
            'status' => 'uploading',
            'upload_token' => $uploadToken,
            'upload_expires_at' => (new \DateTimeImmutable())->modify('+4 hours'),
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedVideo = $this->videoRepository->save($video);
        $this->logger->debug('Video record created', ['video_id' => $savedVideo->getId()]);

        try {
            $storageLocation = $this->videoRepository->initializeStorage(
                $savedVideo->getId(),
                $videoData['size']
            );

            $this->videoRepository->updateStorageLocation(
                $savedVideo->getId(),
                $storageLocation
            );

            $this->videoRepository->updateStatus($savedVideo->getId(), 'upload_received');

            $this->logger->info('Video upload initialized', [
                'video_id' => $savedVideo->getId(),
                'storage_location' => $storageLocation
            ]);

            return new VideoUploadResult([
                'success' => true,
                'video_id' => $savedVideo->getId(),
                'upload_url' => $this->buildUploadUrl($uploadToken),
                'expires_at' => $video->getUploadExpiresAt()->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->videoRepository->updateStatus($savedVideo->getId(), 'failed');
            $this->logger->error('Video upload initialization failed', [
                'video_id' => $savedVideo->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function processVideo(string $videoId, array $transcodeProfiles): ProcessingResult
    {
        $video = $this->videoRepository->findById($videoId);
        if ($video === null) {
            throw new StreamException("Video not found: {$videoId}");
        }

        if ($video->getStatus() !== 'upload_received') {
            throw new StreamException("Video is not ready for processing, status: {$video->getStatus()}");
        }

        $this->videoRepository->updateStatus($videoId, 'processing');
        $this->logger->debug('Video status updated to processing', ['video_id' => $videoId]);

        try {
            $duration = $this->mediaProcessor->extractDuration($video->getStorageLocation());
            $this->videoRepository->updateMetadata($videoId, ['duration_seconds' => $duration]);

            $jobs = [];
            foreach ($transcodeProfiles as $profile) {
                $profileEntity = TranscodeProfile::fromArray($profile);

                $job = TranscodeJob::create([
                    'video_id' => $videoId,
                    'profile_id' => $profileEntity->getId(),
                    'status' => 'queued',
                    'priority' => $profileEntity->getPriority(),
                    'estimated_cost' => $this->calculateTranscodeCost($video->getSizeBytes(), $profileEntity),
                    'created_at' => new \DateTimeImmutable()
                ]);

                $savedJob = $this->transcodeRepo->save($job);
                $jobs[] = $savedJob;

                $this->transcodeRepo->enqueueJob($savedJob->getId(), $profileEntity->getQueueName());
            }

            $this->videoRepository->updateStatus($videoId, 'transcoding');

            $this->logger->info('Video processing initiated', [
                'video_id' => $videoId,
                'transcode_jobs_count' => count($jobs)
            ]);

            return new ProcessingResult([
                'success' => true,
                'video_id' => $videoId,
                'jobs' => array_map(fn($j) => ['job_id' => $j->getId(), 'profile' => $j->getProfileId()], $jobs),
                'duration_seconds' => $duration
            ]);

        } catch (\Throwable $e) {
            $this->videoRepository->updateStatus($videoId, 'failed');
            $this->logger->error('Video processing failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function publishVideo(string $videoId): PublishResult
    {
        $video = $this->videoRepository->findById($videoId);
        if ($video === null) {
            throw new StreamException("Video not found: {$videoId}");
        }

        $pendingJobs = $this->transcodeRepo->findPendingJobsForVideo($videoId);
        if (count($pendingJobs) > 0) {
            throw new StreamException('Cannot publish video with pending transcode jobs');
        }

        $this->videoRepository->updateStatus($videoId, 'publishing');

        try {
            $playbackUrls = $this->deliveryRepo->generatePlaybackUrls($videoId);

            $this->videoRepository->updateStatus($videoId, 'ready');
            $this->videoRepository->updatePlaybackUrls($videoId, $playbackUrls);

            $this->cdnInvalidator->invalidateForVideo($videoId);

            $this->logger->info('Video published successfully', [
                'video_id' => $videoId,
                'playback_urls' => array_keys($playbackUrls)
            ]);

            return new PublishResult([
                'success' => true,
                'video_id' => $videoId,
                'playback_urls' => $playbackUrls,
                'published_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->videoRepository->updateStatus($videoId, 'publish_failed');
            $this->logger->error('Video publish failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function buildUploadUrl(string $token): string
    {
        return "https://upload.cloudflare.stream?token={$token}";
    }

    private function calculateTranscodeCost(int $sizeBytes, TranscodeProfile $profile): float
    {
        $baseUnits = $sizeBytes / (1024 * 1024);
        return $baseUnits * $profile->getCostPerMinute();
    }
}
