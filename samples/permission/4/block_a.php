<?php
declare(strict_types=1);

namespace App\Document\Security;

use App\Domain\Entity\User;
use App\Domain\Entity\File;
use App\Domain\Repository\FileRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class FilePermissionService
{
    public function __construct(
        private FileRepositoryInterface $fileRepository,
        private LoggerInterface $logger,
    ) {}

    public function canReadFile(User $user, string $fileId): bool
    {
        if ($user === null) {
            $this->logger->warning('File read permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('File read permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return false;
        }

        $file = $this->fileRepository->findById($fileId);
        if ($file === null) {
            $this->logger->info('File read permission denied: file not found', [
                'file_id' => $fileId,
            ]);
            return false;
        }

        if ($this->userIsFileOwner($user, $file)) {
            $this->logger->debug('File read permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return true;
        }

        if ($this->userHasFilePermission($user, $file, 'read')) {
            $this->logger->debug('File read permission granted: direct permission', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return true;
        }

        if ($this->userSharesParentFolder($user, $file)) {
            $this->logger->debug('File read permission granted: parent folder access', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return true;
        }

        $this->logger->info('File read permission denied: no access', [
            'user_id' => $user->getId()->toString(),
            'file_id' => $fileId,
        ]);

        return false;
    }

    public function canWriteFile(User $user, string $fileId): bool
    {
        if ($user === null) {
            $this->logger->warning('File write permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('File write permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return false;
        }

        $file = $this->fileRepository->findById($fileId);
        if ($file === null) {
            $this->logger->info('File write permission denied: file not found', [
                'file_id' => $fileId,
            ]);
            return false;
        }

        if ($this->userIsFileOwner($user, $file)) {
            $this->logger->debug('File write permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return true;
        }

        if ($this->userHasFilePermission($user, $file, 'write')) {
            $this->logger->debug('File write permission granted: direct permission', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return true;
        }

        if ($this->userSharesParentFolder($user, $file) && $this->parentFolderAllowsWrite($user, $file)) {
            $this->logger->debug('File write permission granted: parent folder access', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return true;
        }

        $this->logger->info('File write permission denied: no access', [
            'user_id' => $user->getId()->toString(),
            'file_id' => $fileId,
        ]);

        return false;
    }

    public function canDeleteFile(User $user, string $fileId): bool
    {
        if ($user === null) {
            $this->logger->warning('File delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('File delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return false;
        }

        $file = $this->fileRepository->findById($fileId);
        if ($file === null) {
            $this->logger->info('File delete permission denied: file not found', [
                'file_id' => $fileId,
            ]);
            return false;
        }

        if ($this->userIsFileOwner($user, $file)) {
            $this->logger->debug('File delete permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return true;
        }

        if ($this->userHasFilePermission($user, $file, 'delete')) {
            $this->logger->debug('File delete permission granted: direct permission', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return true;
        }

        if ($this->userSharesParentFolder($user, $file) && $this->parentFolderAllowsDelete($user, $file)) {
            $this->logger->debug('File delete permission granted: parent folder access', [
                'user_id' => $user->getId()->toString(),
                'file_id' => $fileId,
            ]);
            return true;
        }

        $this->logger->info('File delete permission denied: no access', [
            'user_id' => $user->getId()->toString(),
            'file_id' => $fileId,
        ]);

        return false;
    }

    private function userIsFileOwner(User $user, File $file): bool
    {
        return $file->getOwnerId()->equals($user->getId());
    }

    private function userHasFilePermission(User $user, File $file, string $action): bool
    {
        return false;
    }

    private function userSharesParentFolder(User $user, File $file): bool
    {
        return false;
    }

    private function parentFolderAllowsWrite(User $user, File $file): bool
    {
        return false;
    }

    private function parentFolderAllowsDelete(User $user, File $file): bool
    {
        return false;
    }
}
