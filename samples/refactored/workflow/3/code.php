<?php
declare(strict_types=1);

namespace App\Core\Content\Publishing;

use Psr\Log\LoggerInterface;

interface PublishableContentInterface
{
    public function getId(): string;
    public function getTitle(): string;
    public function getPrimaryKeyword(): ?string;
}

interface PublishingStepInterface
{
    public function execute(PublishableContentInterface $content): void;
    public function getName(): string;
}

abstract class BasePublishingWorkflow
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function publish(string $contentId): void
    {
        $content = $this->findContent($contentId);
        $this->validateContent($content);
        $this->logger->info("Starting publishing workflow", ['content_id' => $contentId]);

        foreach ($this->getSteps() as $step) {
            $this->executeStep($step, $content);
        }

        $this->finalizePublishing($content);
        $this->logger->info("Publishing workflow completed", ['content_id' => $contentId]);
    }

    protected function executeStep(PublishingStepInterface $step, PublishableContentInterface $content): void
    {
        $this->logger->debug("Executing step: {$step->getName()}", ['content_id' => $content->getId()]);
        $step->execute($content);
    }

    protected function recordAuditEvent(PublishableContentInterface $content, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'content_id' => $content->getId(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }

    abstract protected function findContent(string $contentId): PublishableContentInterface;
    abstract protected function validateContent(PublishableContentInterface $content): void;
    abstract protected function getSteps(): array;
    abstract protected function finalizePublishing(PublishableContentInterface $content): void;
}

final class ArticlePublishingWorkflow extends BasePublishingWorkflow
{
    protected function findContent(string $contentId): PublishableContentInterface { throw new \RuntimeException('Not implemented'); }
    protected function validateContent(PublishableContentInterface $content): void { }
    protected function getSteps(): array { return []; }
    protected function finalizePublishing(PublishableContentInterface $content): void { }
}
final class ProductPublishingWorkflow extends BasePublishingWorkflow
{
    protected function findContent(string $contentId): PublishableContentInterface { throw new \RuntimeException('Not implemented'); }
    protected function validateContent(PublishableContentInterface $content): void { }
    protected function getSteps(): array { return []; }
    protected function finalizePublishing(PublishableContentInterface $content): void { }
}
final class MediaPublishingWorkflow extends BasePublishingWorkflow
{
    protected function findContent(string $contentId): PublishableContentInterface { throw new \RuntimeException('Not implemented'); }
    protected function validateContent(PublishableContentInterface $content): void { }
    protected function getSteps(): array { return []; }
    protected function finalizePublishing(PublishableContentInterface $content): void { }
}
