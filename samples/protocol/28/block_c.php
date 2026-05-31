<?php
declare(strict_types=1);

namespace App\Services\Email;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Attachment;

final class NotificationEmailService
{
    private MailerInterface $mailer;
    private ConfigManager $config;
    private LoggerInterface $logger;
    private string $fromAddress;
    private string $fromName;

    public function __construct(
        MailerInterface $mailer,
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->mailer = $mailer;
        $this->config = $config;
        $this->logger = $logger;
        $this->fromAddress = $config->get('email.from_address', 'noreply@example.com');
        $this->fromName = $config->get('email.from_name', 'Example');
    }

    public function send(string $to, string $subject, string $body, array $attachments = []): bool
    {
        try {
            $email = $this->buildEmail($to, $subject, $body);
            
            foreach ($attachments as $attachment) {
                $this->addAttachment($email, $attachment);
            }
            
            $this->mailer->send($email);
            
            $this->logger->info('Notification email sent', [
                'to' => $to,
                'subject' => $subject,
                'attachment_count' => count($attachments),
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to send notification email', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function sendAlert(string $to, string $alertType, array $details): bool
    {
        $subject = "Alert: {$alertType}";
        $body = $this->buildAlertBody($alertType, $details);
        
        return $this->send($to, $subject, $body);
    }

    private function buildEmail(string $to, string $subject, string $body): Email
    {
        return (new Email())
            ->from("{$this->fromName} <{$this->fromAddress}>")
            ->to($to)
            ->subject($subject)
            ->html($body)
            ->text(strip_tags($body));
    }

    private function addAttachment(Email $email, array $attachment): void
    {
        if (isset($attachment['path'])) {
            $email->attachFromPath(
                $attachment['path'],
                $attachment['name'] ?? null,
                $attachment['content_type'] ?? null
            );
        } elseif (isset($attachment['content'])) {
            $email->attach(
                $attachment['content'],
                $attachment['name'] ?? 'attachment',
                $attachment['content_type'] ?? null
            );
        }
    }

    private function buildAlertBody(string $alertType, array $details): string
    {
        $html = "<h2>Alert: {$alertType}</h2>";
        $html .= "<p>Alert details:</p><ul>";
        
        foreach ($details as $key => $value) {
            $html .= "<li><strong>{$key}:</strong> {$value}</li>";
        }
        
        $html .= "</ul>";
        return $html;
    }
}
