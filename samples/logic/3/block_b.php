<?php

declare(strict_types=1);

namespace App\Media;

use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\VideoTranscoder;
use Psr\Log\LoggerInterface;

final class VideoPublishingService
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoTranscoder $transcoder,
        private readonly LoggerInterface $logger,
    ) {}

    public function publishVideo(int $videoId, int $userId): Video
    {
        $video = $this->videoRepository->findById($videoId);
        $user = $this->loadUser($userId);

        if ($video === null) {
            throw new \RuntimeException('Video not found');
        }

        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        if ($user->getSubscriptionTier() !== 'premium' && $user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Publishing videos requires premium or enterprise subscription');
        }

        if ($user->getSubscriptionTier() === 'premium' && $user->getPublishedVideosThisMonth() >= 5) {
            throw new \InvalidArgumentException('Premium users can publish up to 5 videos per month');
        }

        if ($user->getSubscriptionTier() === 'enterprise' && $user->getPublishedVideosThisMonth() >= 50) {
            throw new \InvalidArgumentException('Enterprise users can publish up to 50 videos per month');
        }

        if ($video->getStatus() === 'published') {
            throw new \InvalidArgumentException('Video is already published');
        }

        if ($video->getStatus() === 'rejected') {
            throw new \InvalidArgumentException('Cannot publish rejected video');
        }

        if (trim($video->getTitle()) === '' || $video->getDurationSeconds() <= 0) {
            throw new \InvalidArgumentException('Video must have title and valid duration');
        }

        if ($video->getDurationSeconds() > 7200 && $user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Videos longer than 2 hours require enterprise subscription');
        }

        $video->setStatus('published');
        $video->setPublishedAt(new \DateTimeImmutable());
        $video->setPublishedBy($userId);

        $user->incrementPublishedVideosThisMonth();
        $this->userRepository->save($user);
        $this->videoRepository->save($video);

        $this->logger->info('Video published successfully', [
            'video_id' => $videoId,
            'user_id' => $userId,
            'tier' => $user->getSubscriptionTier(),
        ]);

        return $video;
    }

    public function updatePublishedVideo(int $videoId, int $userId, array $updates): Video
    {
        $video = $this->videoRepository->findById($videoId);
        $user = $this->loadUser($userId);

        if ($video === null || $user === null) {
            throw new \RuntimeException('Video or user not found');
        }

        if ($video->getPublishedBy() !== $userId) {
            throw new \InvalidArgumentException('Only the original uploader can update the video');
        }

        if ($user->getSubscriptionTier() !== 'premium' && $user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Updating videos requires premium or enterprise subscription');
        }

        if ($video->getStatus() !== 'published') {
            throw new \InvalidArgumentException('Can only update published videos');
        }

        if ($user->getSubscriptionTier() === 'free' || $user->getSubscriptionTier() === 'basic') {
            throw new \InvalidArgumentException('Free and basic users cannot update published videos');
        }

        $video->setTitle($updates['title'] ?? $video->getTitle());
        $video->setDescription($updates['description'] ?? $video->getDescription());
        $video->setUpdatedAt(new \DateTimeImmutable());

        $this->videoRepository->save($video);

        return $video;
    }

    public function deleteVideo(int $videoId, int $userId): bool
    {
        $video = $this->videoRepository->findById($videoId);
        $user = $this->loadUser($userId);

        if ($video === null || $user === null) {
            throw new \RuntimeException('Video or user not found');
        }

        if ($user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Deleting videos requires enterprise subscription');
        }

        if ($video->getStatus() === 'deleted') {
            throw new \InvalidArgumentException('Video is already deleted');
        }

        $video->setStatus('deleted');
        $video->setDeletedAt(new \DateTimeImmutable());
        $video->setDeletedBy($userId);

        $this->videoRepository->save($video);

        $this->logger->info('Video deleted successfully', [
            'video_id' => $videoId,
            'user_id' => $userId,
        ]);

        return true;
    }

    private function loadUser(int $userId): ?User
    {
        return $this->userRepository->findById($userId);
    }
}
