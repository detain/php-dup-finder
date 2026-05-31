<?php
declare(strict_types=1);

namespace App\Webhook\Security;

use App\Domain\Entity\User;
use App\Domain\Entity\WebhookSubscription;
use App\Domain\Repository\WebhookSubscriptionRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class WebhookPermissionService
{
    public function __construct(
        private WebhookSubscriptionRepositoryInterface $subscriptionRepository,
        private LoggerInterface $logger,
    ) {}

    public function canCreateWebhook(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Webhook create permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Webhook create permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('webhook', 'create')) {
            $this->logger->info('Webhook create permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Webhook create permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canUpdateWebhook(User $user, string $webhookId): bool
    {
        if ($user === null) {
            $this->logger->warning('Webhook update permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Webhook update permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'webhook_id' => $webhookId,
            ]);
            return false;
        }

        $subscription = $this->subscriptionRepository->findById($webhookId);
        if ($subscription === null) {
            $this->logger->info('Webhook update permission denied: webhook not found', [
                'webhook_id' => $webhookId,
            ]);
            return false;
        }

        if (!$subscription->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('webhook', 'update_others')) {
                $this->logger->info('Webhook update permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'webhook_id' => $webhookId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Webhook update permission granted', [
            'user_id' => $user->getId()->toString(),
            'webhook_id' => $webhookId,
        ]);

        return true;
    }

    public function canDeleteWebhook(User $user, string $webhookId): bool
    {
        if ($user === null) {
            $this->logger->warning('Webhook delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Webhook delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'webhook_id' => $webhookId,
            ]);
            return false;
        }

        $subscription = $this->subscriptionRepository->findById($webhookId);
        if ($subscription === null) {
            $this->logger->info('Webhook delete permission denied: webhook not found', [
                'webhook_id' => $webhookId,
            ]);
            return false;
        }

        if (!$subscription->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('webhook', 'delete_others')) {
                $this->logger->info('Webhook delete permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'webhook_id' => $webhookId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Webhook delete permission granted', [
            'user_id' => $user->getId()->toString(),
            'webhook_id' => $webhookId,
        ]);

        return true;
    }

    public function canViewWebhookLogs(User $user, string $webhookId): bool
    {
        if ($user === null) {
            $this->logger->warning('Webhook logs view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Webhook logs view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'webhook_id' => $webhookId,
            ]);
            return false;
        }

        $subscription = $this->subscriptionRepository->findById($webhookId);
        if ($subscription === null) {
            $this->logger->info('Webhook logs view permission denied: webhook not found', [
                'webhook_id' => $webhookId,
            ]);
            return false;
        }

        if (!$subscription->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('webhook', 'view_others_logs')) {
                $this->logger->info('Webhook logs view permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'webhook_id' => $webhookId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Webhook logs view permission granted', [
            'user_id' => $user->getId()->toString(),
            'webhook_id' => $webhookId,
        ]);

        return true;
    }

    public function canRetryWebhookDelivery(User $user, string $webhookId): bool
    {
        if ($user === null) {
            $this->logger->warning('Webhook retry permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Webhook retry permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'webhook_id' => $webhookId,
            ]);
            return false;
        }

        $subscription = $this->subscriptionRepository->findById($webhookId);
        if ($subscription === null) {
            $this->logger->info('Webhook retry permission denied: webhook not found', [
                'webhook_id' => $webhookId,
            ]);
            return false;
        }

        if (!$subscription->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('webhook', 'retry_others')) {
                $this->logger->info('Webhook retry permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'webhook_id' => $webhookId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Webhook retry permission granted', [
            'user_id' => $user->getId()->toString(),
            'webhook_id' => $webhookId,
        ]);

        return true;
    }
}
