<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\AlertSubscription;
use App\Models\Incident;
use Psr\Log\LoggerInterface;

final class IncidentAlertService
{
    private const ALERTS_ACTIVE = true;
    private const ALERT_SENDER = 'alerts@example.com';
    private const ALERT_SENDER_NAME = 'Alert System';
    private const TEMPLATE_DIRECTORY = '/resources/templates/alerts';
    private const SENDING_BATCH_SIZE = 50;
    private const MAX_SEND_ATTEMPTS = 3;
    private const RETRY_WAIT_TIME = 1000;
    private const TRACK_EMAIL_OPENS = true;
    private const TRACK_LINK_CLICKS = true;
    private const ADD_UNSUBSCRIBE_LINK = true;
    private const UNSUBSCRIBE_ENDPOINT = 'https://example.com/alert-unsubscribe';
    private const MAX_EMAIL_RECIPIENTS = 100;
    private const SEND_TIMEOUT = 30;
    private const PRIORITY_IMMEDIATE = 1;
    private const PRIORITY_STANDARD = 3;
    private const PRIORITY_DEFERRED = 5;

    private AlertMailer $alertMailer;
    private LoggerInterface $log;

    public function __construct(AlertMailer $alertMailer, LoggerInterface $log)
    {
        $this->alertMailer = $alertMailer;
        $this->log = $log;
    }

    public function sendIncidentCreatedAlert(Incident $incident, array $subscribers): bool
    {
        if (!self::ALERTS_ACTIVE) {
            return false;
        }

        $template = $this->fetchAlertTemplate('incident_created');
        $payload = $this->compileIncidentPayload($incident);

        $alertEmail = new AlertEmail();
        $alertEmail->setRecipients($this->extractEmails($subscribers));
        $alertEmail->setFrom(self::ALERT_SENDER, self::ALERT_SENDER_NAME);
        $alertEmail->setSubject('Incident Created: ' . $incident->title);
        $alertEmail->setHtmlContent($this->interpolateTemplate($template, $payload));
        $alertEmail->setPriority(self::PRIORITY_IMMEDIATE);

        if (self::ADD_UNSUBSCRIBE_LINK) {
            $alertEmail->appendHeader('List-Unsubscribe', self::UNSUBSCRIBE_ENDPOINT . '?id=' . $incident->id);
        }

        if (self::TRACK_EMAIL_OPENS) {
            $alertEmail->appendHeader('X-Track-Opens', 'enabled');
        }

        if (self::TRACK_LINK_CLICKS) {
            $alertEmail->appendHeader('X-Track-Clicks', 'enabled');
        }

        return $this->dispatchAlert($alertEmail, [
            'incident_id' => $incident->id,
            'alert_type' => 'incident_created',
        ]);
    }

    public function sendIncidentUpdatedAlert(Incident $incident, array $subscribers): bool
    {
        if (!self::ALERTS_ACTIVE) {
            return false;
        }

        $template = $this->fetchAlertTemplate('incident_updated');
        $payload = $this->compileIncidentPayload($incident);
        $payload['update_message'] = $incident->latest_update;

        $alertEmail = new AlertEmail();
        $alertEmail->setRecipients($this->extractEmails($subscribers));
        $alertEmail->setFrom(self::ALERT_SENDER, self::ALERT_SENDER_NAME);
        $alertEmail->setSubject('Incident Updated: ' . $incident->title);
        $alertEmail->setHtmlContent($this->interpolateTemplate($template, $payload));
        $alertEmail->setPriority(self::PRIORITY_STANDARD);

        if (self::ADD_UNSUBSCRIBE_LINK) {
            $alertEmail->appendHeader('List-Unsubscribe', self::UNSUBSCRIBE_ENDPOINT . '?id=' . $incident->id);
        }

        return $this->dispatchAlert($alertEmail, [
            'incident_id' => $incident->id,
            'alert_type' => 'incident_updated',
        ]);
    }

    public function sendIncidentResolvedAlert(Incident $incident, array $subscribers): bool
    {
        if (!self::ALERTS_ACTIVE) {
            return false;
        }

        $template = $this->fetchAlertTemplate('incident_resolved');
        $payload = $this->compileIncidentPayload($incident);
        $payload['resolved_at'] = $incident->resolved_at->format('Y-m-d H:i:s');
        $payload['duration_minutes'] = $incident->getDurationMinutes();

        $alertEmail = new AlertEmail();
        $alertEmail->setRecipients($this->extractEmails($subscribers));
        $alertEmail->setFrom(self::ALERT_SENDER, self::ALERT_SENDER_NAME);
        $alertEmail->setSubject('Incident Resolved: ' . $incident->title);
        $alertEmail->setHtmlContent($this->interpolateTemplate($template, $payload));
        $alertEmail->setPriority(self::PRIORITY_STANDARD);

        if (self::ADD_UNSUBSCRIBE_LINK) {
            $alertEmail->appendHeader('List-Unsubscribe', self::UNSUBSCRIBE_ENDPOINT . '?id=' . $incident->id);
        }

        return $this->dispatchAlert($alertEmail, [
            'incident_id' => $incident->id,
            'alert_type' => 'incident_resolved',
        ]);
    }

    public function sendIncidentCancelledAlert(Incident $incident, array $subscribers): bool
    {
        if (!self::ALERTS_ACTIVE) {
            return false;
        }

        $template = $this->fetchAlertTemplate('incident_cancelled');
        $payload = $this->compileIncidentPayload($incident);
        $payload['cancellation_reason'] = $incident->cancellation_reason;

        $alertEmail = new AlertEmail();
        $alertEmail->setRecipients($this->extractEmails($subscribers));
        $alertEmail->setFrom(self::ALERT_SENDER, self::ALERT_SENDER_NAME);
        $alertEmail->setSubject('Incident Cancelled: ' . $incident->title);
        $alertEmail->setHtmlContent($this->interpolateTemplate($template, $payload));
        $alertEmail->setPriority(self::PRIORITY_DEFERRED);

        if (self::ADD_UNSUBSCRIBE_LINK) {
            $alertEmail->appendHeader('List-Unsubscribe', self::UNSUBSCRIBE_ENDPOINT . '?id=' . $incident->id);
        }

        return $this->dispatchAlert($alertEmail, [
            'incident_id' => $incident->id,
            'alert_type' => 'incident_cancelled',
        ]);
    }

    public function broadcastAlertBatch(array $incidents): array
    {
        $report = [
            'total_alerts' => 0,
            'successful' => 0,
            'unsuccessful' => 0,
            'errors' => [],
        ];

        $batches = array_chunk($incidents, self::SENDING_BATCH_SIZE);

        foreach ($batches as $batch) {
            foreach ($batch as $incidentData) {
                $incident = $incidentData['incident'];
                $subscribers = $incidentData['subscribers'];

                $sent = $this->sendIncidentCreatedAlert($incident, $subscribers);

                $report['total_alerts']++;

                if ($sent) {
                    $report['successful']++;
                } else {
                    $report['unsuccessful']++;
                    $report['errors'][] = $incident->id;
                }
            }

            if (count($batches) > 1) {
                usleep(self::RETRY_WAIT_TIME * 1000);
            }
        }

        $this->log->info('Incident alert batch completed', [
            'total' => $report['total_alerts'],
            'successful' => $report['successful'],
            'failed' => $report['unsuccessful'],
            'batch_size' => self::SENDING_BATCH_SIZE,
        ]);

        return $report;
    }

    private function dispatchAlert(AlertEmail $email, array $context): bool
    {
        $attempt = 0;

        while ($attempt < self::MAX_SEND_ATTEMPTS) {
            try {
                $this->alertMailer->send($email);

                $this->log->info('Alert dispatched successfully', [
                    'recipients_count' => count($email->getRecipients()),
                    'subject' => $email->getSubject(),
                    'attempt' => $attempt + 1,
                    'timeout' => self::SEND_TIMEOUT,
                    'context' => $context,
                ]);

                return true;
            } catch (\Exception $e) {
                $attempt++;

                $this->log->error('Alert dispatch failed', [
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_SEND_ATTEMPTS,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_WAIT_TIME,
                    'context' => $context,
                ]);

                if ($attempt >= self::MAX_SEND_ATTEMPTS) {
                    return false;
                }

                usleep(self::RETRY_WAIT_TIME * 1000 * $attempt);
            }
        }

        return false;
    }

    private function fetchAlertTemplate(string $templateName): string
    {
        $filePath = self::TEMPLATE_DIRECTORY . '/' . $templateName . '.html';
        $content = file_get_contents($filePath);
        return $content !== false ? $content : '';
    }

    private function interpolateTemplate(string $template, array $data): string
    {
        $output = $template;

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $output = str_replace('{{' . $key . '}}', htmlspecialchars((string) $value), $output);
            }
        }

        return $output;
    }

    private function compileIncidentPayload(Incident $incident): array
    {
        return [
            'incident_id' => $incident->id,
            'incident_title' => $incident->title,
            'incident_severity' => $incident->severity,
            'incident_status' => $incident->status,
            'created_at' => $incident->created_at->format('Y-m-d H:i:s'),
            'organization_name' => 'Example Organization',
            'support_email' => 'support@example.com',
            'dashboard_url' => 'https://example.com/incidents/' . $incident->id,
        ];
    }

    private function extractEmails(array $recipients): array
    {
        return array_map(fn($r) => $r instanceof AlertSubscription ? $r->email : $r, $recipients);
    }

    public function isEnabled(): bool
    {
        return self::ALERTS_ACTIVE;
    }

    public function getMaximumRecipients(): int
    {
        return self::MAX_EMAIL_RECIPIENTS;
    }
}
