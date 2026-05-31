<?php
declare(strict_types=1);

namespace App\Notification\Authorization;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class NotificationTemplatePermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function canCreateTemplate(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Template create permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Template create permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('notification_template', 'create')) {
            $this->logger->info('Template create permission denied: no create permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Template create permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canEditTemplate(User $user, string $templateId): bool
    {
        if ($user === null) {
            $this->logger->warning('Template edit permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Template edit permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'template_id' => $templateId,
            ]);
            return false;
        }

        if (!$user->hasPermission('notification_template', 'edit')) {
            $this->logger->info('Template edit permission denied: no edit permission', [
                'user_id' => $user->getId()->toString(),
                'template_id' => $templateId,
            ]);
            return false;
        }

        $this->logger->debug('Template edit permission granted', [
            'user_id' => $user->getId()->toString(),
            'template_id' => $templateId,
        ]);

        return true;
    }

    public function canDeleteTemplate(User $user, string $templateId): bool
    {
        if ($user === null) {
            $this->logger->warning('Template delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Template delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'template_id' => $templateId,
            ]);
            return false;
        }

        if (!$user->hasPermission('notification_template', 'delete')) {
            $this->logger->info('Template delete permission denied: no delete permission', [
                'user_id' => $user->getId()->toString(),
                'template_id' => $templateId,
            ]);
            return false;
        }

        $this->logger->debug('Template delete permission granted', [
            'user_id' => $user->getId()->toString(),
            'template_id' => $templateId,
        ]);

        return true;
    }

    public function canApproveTemplate(User $user, string $templateId): bool
    {
        if ($user === null) {
            $this->logger->warning('Template approve permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Template approve permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'template_id' => $templateId,
            ]);
            return false;
        }

        if (!$user->hasPermission('notification_template', 'approve')) {
            $this->logger->info('Template approve permission denied: no approve permission', [
                'user_id' => $user->getId()->toString(),
                'template_id' => $templateId,
            ]);
            return false;
        }

        $this->logger->debug('Template approve permission granted', [
            'user_id' => $user->getId()->toString(),
            'template_id' => $templateId,
        ]);

        return true;
    }
}
