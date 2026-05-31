<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\Appointment;
use Psr\Log\LoggerInterface;
use App\Mailer\MailerClient;

final class AppointmentNotificationService
{
    private const EMAIL_NOTIFICATIONS_ENABLED = true;
    private const SENDER_EMAIL = 'notifications@example.com';
    private const SENDER_NAME = 'Appointment System';
    private const EMAIL_TEMPLATE_DIR = '/templates/appointments';
    private const EMAIL_BATCH_SIZE = 50;
    private const MAX_EMAIL_RETRIES = 3;
    private const RETRY_WAIT_MS = 1000;
    private const ENABLE_OPEN_TRACKING = true;
    private const ENABLE_CLICK_TRACKING = true;
    private const INCLUDE_UNSUBSCRIBE_HEADER = true;
    private const UNSUBSCRIBE_LINK = 'https://example.com/emails/unsubscribe';
    private const MAX_TO_ADDRESSES = 100;
    private const SMTP_TIMEOUT = 30;
    private const MESSAGE_PRIORITY_URGENT = 1;
    private const MESSAGE_PRIORITY_NORMAL = 3;
    private const MESSAGE_PRIORITY_LOW = 5;

    private MailerClient $mailerClient;
    private LoggerInterface $logger;

    public function __construct(MailerClient $mailerClient, LoggerInterface $logger)
    {
        $this->mailerClient = $mailerClient;
        $this->logger = $logger;
    }

    public function sendAppointmentReminder(Appointment $appointment, User $user): bool
    {
        if (!self::EMAIL_NOTIFICATIONS_ENABLED) {
            return false;
        }

        $template = $this->loadEmailTemplate('appointment_reminder');
        $templateData = $this->buildAppointmentVariables($appointment);

        $email = new EmailMessage();
        $email->setToAddress($user->email);
        $email->setFromAddress(self::SENDER_EMAIL, self::SENDER_NAME);
        $email->setSubject('Appointment Reminder - ' . $appointment->title);
        $email->setHtmlBody($this->renderTemplate($template, $templateData));
        $email->setPriority(self::MESSAGE_PRIORITY_NORMAL);

        if (self::INCLUDE_UNSUBSCRIBE_HEADER) {
            $email->addCustomHeader('List-Unsubscribe', self::UNSUBSCRIBE_LINK . '?uid=' . $user->id);
        }

        if (self::ENABLE_OPEN_TRACKING) {
            $email->addCustomHeader('X-Open-Tracking', 'enabled');
        }

        if (self::ENABLE_CLICK_TRACKING) {
            $email->addCustomHeader('X-Click-Tracking', 'enabled');
        }

        return $this->sendEmail($email, [
            'user_id' => $user->id,
            'appointment_id' => $appointment->id,
            'type' => 'reminder',
        ]);
    }

    public function sendAppointmentConfirmation(Appointment $appointment, User $user): bool
    {
        if (!self::EMAIL_NOTIFICATIONS_ENABLED) {
            return false;
        }

        $template = $this->loadEmailTemplate('appointment_confirmed');
        $templateData = $this->buildAppointmentVariables($appointment);
        $templateData['confirmation_code'] = $appointment->confirmation_code;
        $templateData['location'] = $appointment->location;

        $email = new EmailMessage();
        $email->setToAddress($user->email);
        $email->setFromAddress(self::SENDER_EMAIL, self::SENDER_NAME);
        $email->setSubject('Appointment Confirmed - ' . $appointment->title);
        $email->setHtmlBody($this->renderTemplate($template, $templateData));
        $email->setPriority(self::MESSAGE_PRIORITY_URGENT);

        if (self::INCLUDE_UNSUBSCRIBE_HEADER) {
            $email->addCustomHeader('List-Unsubscribe', self::UNSUBSCRIBE_LINK . '?uid=' . $user->id);
        }

        if (self::ENABLE_OPEN_TRACKING) {
            $email->addCustomHeader('X-Open-Tracking', 'enabled');
        }

        return $this->sendEmail($email, [
            'user_id' => $user->id,
            'appointment_id' => $appointment->id,
            'type' => 'confirmation',
        ]);
    }

    public function sendAppointmentCancellation(Appointment $appointment, User $user): bool
    {
        if (!self::EMAIL_NOTIFICATIONS_ENABLED) {
            return false;
        }

        $template = $this->loadEmailTemplate('appointment_cancelled');
        $templateData = $this->buildAppointmentVariables($appointment);
        $templateData['cancellation_reason'] = $appointment->cancellation_reason ?? 'Not provided';

        $email = new EmailMessage();
        $email->setToAddress($user->email);
        $email->setFromAddress(self::SENDER_EMAIL, self::SENDER_NAME);
        $email->setSubject('Appointment Cancelled - ' . $appointment->title);
        $email->setHtmlBody($this->renderTemplate($template, $templateData));
        $email->setPriority(self::MESSAGE_PRIORITY_URGENT);

        if (self::INCLUDE_UNSUBSCRIBE_HEADER) {
            $email->addCustomHeader('List-Unsubscribe', self::UNSUBSCRIBE_LINK . '?uid=' . $user->id);
        }

        return $this->sendEmail($email, [
            'user_id' => $user->id,
            'appointment_id' => $appointment->id,
            'type' => 'cancellation',
        ]);
    }

    public function sendAppointmentRescheduled(Appointment $appointment, User $user): bool
    {
        if (!self::EMAIL_NOTIFICATIONS_ENABLED) {
            return false;
        }

        $template = $this->loadEmailTemplate('appointment_rescheduled');
        $templateData = $this->buildAppointmentVariables($appointment);
        $templateData['previous_date'] = $appointment->previous_start_time;
        $templateData['new_date'] = $appointment->start_time;

        $email = new EmailMessage();
        $email->setToAddress($user->email);
        $email->setFromAddress(self::SENDER_EMAIL, self::SENDER_NAME);
        $email->setSubject('Appointment Rescheduled - ' . $appointment->title);
        $email->setHtmlBody($this->renderTemplate($template, $templateData));
        $email->setPriority(self::MESSAGE_PRIORITY_HIGH);

        if (self::INCLUDE_UNSUBSCRIBE_HEADER) {
            $email->addCustomHeader('List-Unsubscribe', self::UNSUBSCRIBE_LINK . '?uid=' . $user->id);
        }

        return $this->sendEmail($email, [
            'user_id' => $user->id,
            'appointment_id' => $appointment->id,
            'type' => 'rescheduled',
        ]);
    }

    public function sendBulkAppointmentReminders(array $appointments): array
    {
        $stats = [
            'sent' => 0,
            'failed' => 0,
            'failed_recipients' => [],
        ];

        $chunks = array_chunk($appointments, self::EMAIL_BATCH_SIZE);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $apptData) {
                $success = $this->sendAppointmentReminder($apptData['appointment'], $apptData['user']);

                if ($success) {
                    $stats['sent']++;
                } else {
                    $stats['failed']++;
                    $stats['failed_recipients'][] = $apptData['user']->email;
                }
            }

            if (count($chunks) > 1) {
                usleep(self::RETRY_WAIT_MS * 1000);
            }
        }

        $this->logger->info('Bulk appointment reminders sent', [
            'total' => count($appointments),
            'sent' => $stats['sent'],
            'failed' => $stats['failed'],
            'batch_size' => self::EMAIL_BATCH_SIZE,
        ]);

        return $stats;
    }

    private function sendEmail(EmailMessage $email, array $metadata): bool
    {
        $attempt = 0;

        while ($attempt < self::MAX_EMAIL_RETRIES) {
            try {
                $this->mailerClient->send($email);

                $this->logger->info('Email notification sent', [
                    'to' => $email->getToAddress(),
                    'subject' => $email->getSubject(),
                    'attempt' => $attempt + 1,
                    'smtp_timeout' => self::SMTP_TIMEOUT,
                    'metadata' => $metadata,
                ]);

                return true;
            } catch (\Exception $e) {
                $attempt++;

                $this->logger->error('Failed to send email notification', [
                    'to' => $email->getToAddress(),
                    'attempt' => $attempt,
                    'max_retries' => self::MAX_EMAIL_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_WAIT_MS,
                ]);

                if ($attempt >= self::MAX_EMAIL_RETRIES) {
                    return false;
                }

                usleep(self::RETRY_WAIT_MS * 1000 * $attempt);
            }
        }

        return false;
    }

    private function loadEmailTemplate(string $templateName): string
    {
        $path = self::EMAIL_TEMPLATE_DIR . '/' . $templateName . '.html';
        return file_get_contents($path) ?: '';
    }

    private function renderTemplate(string $template, array $data): string
    {
        $rendered = $template;

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $rendered = str_replace('{{' . $key . '}}', (string) $value, $rendered);
            }
        }

        return $rendered;
    }

    private function buildAppointmentVariables(Appointment $appointment): array
    {
        return [
            'appointment_title' => $appointment->title,
            'appointment_date' => $appointment->start_time->format('Y-m-d'),
            'appointment_time' => $appointment->start_time->format('H:i'),
            'duration_minutes' => $appointment->duration_minutes,
            'provider_name' => $appointment->provider->name,
            'user_name' => $appointment->user->name,
            'company_name' => self::SENDER_NAME,
            'support_contact' => 'support@example.com',
        ];
    }

    public function notificationsEnabled(): bool
    {
        return self::EMAIL_NOTIFICATIONS_ENABLED;
    }

    public function getMaxRecipientsPerBatch(): int
    {
        return self::MAX_TO_ADDRESSES;
    }
}
