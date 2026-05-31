<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\NotifiableInterface;
use App\Repository\NotifiableRepositoryInterface;
use App\Service\EmailService;
use App\Service\SmsService;
use Psr\Log\LoggerInterface;

interface NotificationServiceInterface
{
    public function notify(NotifiableInterface $entity, string $event): void;
}

abstract class AbstractNotificationService implements NotificationServiceInterface
{
    public function __construct(
        protected readonly NotifiableRepositoryInterface $repository,
        protected readonly EmailService $emailService,
        protected readonly SmsService $smsService,
        protected readonly LoggerInterface $logger,
    ) {}

    public function notify(NotifiableInterface $entity, string $event): void
    {
        $recipient = $entity->getRecipient();
        $template = $this->getTemplateName($event);

        $emailResult = $this->emailService->send(
            $recipient->getEmail(),
            $this->renderEmailTemplate($template, $entity),
            $this->getEmailSubject($event, $entity)
        );

        if (!$emailResult) {
            $this->logger->error('Failed to send email notification', [
                'entity_id' => $entity->getId(),
                'event' => $event,
                'recipient_email' => $recipient->getEmail(),
            ]);
        }

        if ($recipient->getPhone()) {
            $smsResult = $this->smsService->send(
                $recipient->getPhone(),
                $this->renderSmsTemplate($template, $entity)
            );

            if (!$smsResult) {
                $this->logger->error('Failed to send SMS notification', [
                    'entity_id' => $entity->getId(),
                    'event' => $event,
                    'recipient_phone' => $recipient->getPhone(),
                ]);
            }
        }

        $this->logger->info('Notifications sent', [
            'entity_type' => $this->getEntityType(),
            'entity_id' => $entity->getId(),
            'event' => $event,
        ]);
    }

    abstract protected function getEntityType(): string;
    abstract protected function getTemplateName(string $event): string;
    abstract protected function getEmailSubject(string $event, NotifiableInterface $entity): string;
    abstract protected function renderEmailTemplate(string $template, NotifiableInterface $entity): string;
    abstract protected function renderSmsTemplate(string $template, NotifiableInterface $entity): string;
}
