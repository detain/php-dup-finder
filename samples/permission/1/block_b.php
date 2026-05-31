<?php
declare(strict_types=1);

namespace App\Security\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\PostRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class ResourceOwnerService
{
    public function __construct(
        private PostRepositoryInterface $postRepository,
        private LoggerInterface $logger,
    ) {}

    public function canEditPost(User $user, string $postId): bool
    {
        if ($user === null) {
            $this->logger->warning('Edit post access check failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Edit post access check failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'post_id' => $postId,
            ]);
            return false;
        }

        $post = $this->postRepository->findById($postId);
        if ($post === null) {
            $this->logger->info('Edit post access check failed: post not found', [
                'post_id' => $postId,
            ]);
            return false;
        }

        if (!$post->getAuthorId()->equals($user->getId())) {
            foreach ($user->getRoles() as $role) {
                if ($role->isAdmin() || $role->isModerator()) {
                    $this->logger->debug('Edit post access granted via elevated role', [
                        'user_id' => $user->getId()->toString(),
                        'post_id' => $postId,
                    ]);
                    return true;
                }
            }
            $this->logger->info('Edit post access check failed: not owner', [
                'user_id' => $user->getId()->toString(),
                'post_id' => $postId,
            ]);
            return false;
        }

        $this->logger->debug('Edit post access granted', [
            'user_id' => $user->getId()->toString(),
            'post_id' => $postId,
        ]);

        return true;
    }

    public function canDeleteComment(User $user, string $commentId): bool
    {
        if ($user === null) {
            $this->logger->warning('Delete comment access check failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Delete comment access check failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'comment_id' => $commentId,
            ]);
            return false;
        }

        $comment = $this->postRepository->findCommentById($commentId);
        if ($comment === null) {
            $this->logger->info('Delete comment access check failed: comment not found', [
                'comment_id' => $commentId,
            ]);
            return false;
        }

        if (!$comment->getAuthorId()->equals($user->getId())) {
            foreach ($user->getRoles() as $role) {
                if ($role->isAdmin() || $role->isModerator()) {
                    $this->logger->debug('Delete comment access granted via elevated role', [
                        'user_id' => $user->getId()->toString(),
                        'comment_id' => $commentId,
                    ]);
                    return true;
                }
            }
            $this->logger->info('Delete comment access check failed: not owner', [
                'user_id' => $user->getId()->toString(),
                'comment_id' => $commentId,
            ]);
            return false;
        }

        $this->logger->debug('Delete comment access granted', [
            'user_id' => $user->getId()->toString(),
            'comment_id' => $commentId,
        ]);

        return true;
    }

    public function canModerateUser(User $user, string $targetUserId): bool
    {
        if ($user === null) {
            $this->logger->warning('Moderate user access check failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Moderate user access check failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'target_user_id' => $targetUserId,
            ]);
            return false;
        }

        if ($user->getId()->toString() === $targetUserId) {
            $this->logger->info('Moderate user access check failed: self-moderation not allowed', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        foreach ($user->getRoles() as $role) {
            if ($role->isAdmin() || $role->isModerator()) {
                $this->logger->debug('Moderate user access granted', [
                    'user_id' => $user->getId()->toString(),
                    'target_user_id' => $targetUserId,
                ]);
                return true;
            }
        }

        $this->logger->info('Moderate user access check failed: insufficient role', [
            'user_id' => $user->getId()->toString(),
            'target_user_id' => $targetUserId,
        ]);

        return false;
    }
}
