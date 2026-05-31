<?php
declare(strict_types=1);

namespace App\Document\Security;

use App\Domain\Entity\User;
use App\Domain\Entity\Folder;
use App\Domain\Repository\FolderRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class FolderPermissionService
{
    public function __construct(
        private FolderRepositoryInterface $folderRepository,
        private LoggerInterface $logger,
    ) {}

    public function canReadFolder(User $user, string $folderId): bool
    {
        if ($user === null) {
            $this->logger->warning('Folder read permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Folder read permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return false;
        }

        $folder = $this->folderRepository->findById($folderId);
        if ($folder === null) {
            $this->logger->info('Folder read permission denied: folder not found', [
                'folder_id' => $folderId,
            ]);
            return false;
        }

        if ($this->userIsFolderOwner($user, $folder)) {
            $this->logger->debug('Folder read permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return true;
        }

        if ($this->userHasFolderPermission($user, $folder, 'read')) {
            $this->logger->debug('Folder read permission granted: direct permission', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return true;
        }

        if ($this->userSharesParentFolder($user, $folder)) {
            $this->logger->debug('Folder read permission granted: parent folder access', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return true;
        }

        $this->logger->info('Folder read permission denied: no access', [
            'user_id' => $user->getId()->toString(),
            'folder_id' => $folderId,
        ]);

        return false;
    }

    public function canWriteFolder(User $user, string $folderId): bool
    {
        if ($user === null) {
            $this->logger->warning('Folder write permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Folder write permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return false;
        }

        $folder = $this->folderRepository->findById($folderId);
        if ($folder === null) {
            $this->logger->info('Folder write permission denied: folder not found', [
                'folder_id' => $folderId,
            ]);
            return false;
        }

        if ($this->userIsFolderOwner($user, $folder)) {
            $this->logger->debug('Folder write permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return true;
        }

        if ($this->userHasFolderPermission($user, $folder, 'write')) {
            $this->logger->debug('Folder write permission granted: direct permission', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return true;
        }

        if ($this->userSharesParentFolder($user, $folder)) {
            $this->logger->debug('Folder write permission granted: parent folder access', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return true;
        }

        $this->logger->info('Folder write permission denied: no access', [
            'user_id' => $user->getId()->toString(),
            'folder_id' => $folderId,
        ]);

        return false;
    }

    public function canDeleteFolder(User $user, string $folderId): bool
    {
        if ($user === null) {
            $this->logger->warning('Folder delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Folder delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return false;
        }

        $folder = $this->folderRepository->findById($folderId);
        if ($folder === null) {
            $this->logger->info('Folder delete permission denied: folder not found', [
                'folder_id' => $folderId,
            ]);
            return false;
        }

        if ($this->userIsFolderOwner($user, $folder)) {
            $this->logger->debug('Folder delete permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return true;
        }

        if ($this->userHasFolderPermission($user, $folder, 'delete')) {
            $this->logger->debug('Folder delete permission granted: direct permission', [
                'user_id' => $user->getId()->toString(),
                'folder_id' => $folderId,
            ]);
            return true;
        }

        $this->logger->info('Folder delete permission denied: no access', [
            'user_id' => $user->getId()->toString(),
            'folder_id' => $folderId,
        ]);

        return false;
    }

    private function userIsFolderOwner(User $user, Folder $folder): bool
    {
        return $folder->getOwnerId()->equals($user->getId());
    }

    private function userHasFolderPermission(User $user, Folder $folder, string $action): bool
    {
        return false;
    }

    private function userSharesParentFolder(User $user, Folder $folder): bool
    {
        return false;
    }
}
