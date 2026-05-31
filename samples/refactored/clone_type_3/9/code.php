<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Service\MailServiceInterface;
use App\Repository\RepositoryInterface;
use Psr\Log\LoggerInterface;

interface JobInterface
{
    public function execute(mixed $entityId): bool;
}

abstract class AbstractMailJob implements JobInterface
{
    public function __construct(
        protected readonly MailServiceInterface $mailService,
        protected readonly RepositoryInterface $repository,
        protected readonly LoggerInterface $logger,
    ) {}

    public function execute(mixed $entityId): bool
    {
        $entity = $this->repository->find($entityId);

        if ($entity === null) {
            $this->logger->error($this->getEntityNotFoundMessage(), [
                $this->getEntityIdName() => $entityId,
            ]);
            return false;
        }

        if (!$this->shouldProcess($entity)) {
            $this->logger->info($this->getSkippedMessage(), [
                $this->getEntityIdName() => $entityId,
                'status' => $this->getEntityStatus($entity),
            ]);
            return true;
        }

        try {
            $result = $this->mailService->send(
                $this->getRecipientEmail($entity),
                $this->getTemplateName(),
                $this->getTemplateData($entity)
            );

            if ($result) {
                $this->logger->info($this->getSuccessMessage(), [
                    $this->getEntityIdName() => $entityId,
                    'recipient' => $this->getRecipientEmail($entity),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error($this->getFailureMessage(), [
                $this->getEntityIdName() => $entityId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    abstract protected function getEntityNotFoundMessage(): string;
    abstract protected function getEntityIdName(): string;
    abstract protected function getSkippedMessage(): string;
    abstract protected function getSuccessMessage(): string;
    abstract protected function getFailureMessage(): string;
    abstract protected function getTemplateName(): string;
    abstract protected function shouldProcess(mixed $entity): bool;
    abstract protected function getEntityStatus(mixed $entity): string;
    abstract protected function getRecipientEmail(mixed $entity): string;
    abstract protected function getTemplateData(mixed $entity): array;
}
