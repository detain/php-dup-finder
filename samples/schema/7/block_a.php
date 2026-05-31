<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Entity;

use App\Domain\Notifications\ValueObject\NotificationId;
use App\Domain\Notifications\ValueObject\NotificationChannel;
use App\Domain\Notifications\ValueObject\NotificationPriority;

/**
 * Doctrine entity for notification templates.
 * This entity is duplicated in:
 * - Database table: notification_templates
 * - Channel configuration schemas
 * - Template engine configuration
 * - Delivery logging system
 *
 * @ORM\Entity(repositoryClass=NotificationTemplateRepository::class)
 * @ORM\Table(name="notification_templates")
 * @ORM\Index(name="idx_template_key", columns={"template_key"}, unique=true)
 * @ORM\Index(name="idx_channel", columns={"channel"})
 * @ORM\Index(name="idx_is_active", columns={"is_active"})
 */
class NotificationTemplate
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $templateKey;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $channel;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private string $name;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $subject;

    /**
     * @ORM\Column(type="text")
     */
    private string $body;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $htmlBody = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $variables = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $metadata = null;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $isActive = true;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $priority = 'normal';

    /**
     * @ORM\Column(type="integer")
     */
    private int $maxRetries = 3;

    /**
     * @ORM\Column(type="integer")
     */
    private int $retryDelaySeconds = 300;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $templateKey,
        string $channel,
        string $name,
        string $subject,
        string $body
    ) {
        $this->id = NotificationId::generate()->toString();
        $this->templateKey = $templateKey;
        $this->channel = $channel;
        $this->name = $name;
        $this->subject = $subject;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setVariables(array $variables): void
    {
        $this->variables = $variables;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getVariables(): ?array
    {
        return $this->variables;
    }

    public function setPriority(string $priority): void
    {
        $validPriorities = ['low', 'normal', 'high', 'urgent'];
        if (!in_array($priority, $validPriorities, true)) {
            throw new \InvalidArgumentException("Invalid priority: {$priority}");
        }
        $this->priority = $priority;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function activate(): void
    {
        $this->isActive = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function render(array $variables): array
    {
        $subject = $this->subject;
        $body = $this->body;

        foreach ($variables as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $subject = str_replace($placeholder, (string) $value, $subject);
            $body = str_replace($placeholder, (string) $value, $body);
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'html_body' => $this->htmlBody ? $this->renderHtml($variables) : null,
        ];
    }

    private function renderHtml(array $variables): string
    {
        $html = $this->htmlBody;
        foreach ($variables as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $html = str_replace($placeholder, (string) $value, $html);
        }
        return $html;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'template_key' => $this->templateKey,
            'channel' => $this->channel,
            'name' => $this->name,
            'description' => $this->description,
            'subject' => $this->subject,
            'body' => $this->body,
            'html_body' => $this->htmlBody,
            'variables' => $this->variables,
            'metadata' => $this->metadata,
            'is_active' => $this->isActive,
            'priority' => $this->priority,
            'max_retries' => $this->maxRetries,
            'retry_delay_seconds' => $this->retryDelaySeconds,
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeImmutable::ATOM),
        ];
    }
}
