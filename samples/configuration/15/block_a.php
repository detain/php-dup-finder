<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Exceptions\EmailDeliveryException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class SmtpEmailService
{
    private const SMTP_TIMEOUT = 15;
    private const SMTP_CONNECTION_TIMEOUT = 5;
    private const SMTP_MAX_RETRIES = 3;
    private const SMTP_RETRY_DELAY = 500;
    private const SMTP_POOL_SIZE = 10;
    private const SMTP_KEEPALIVE = 30;
    private const BATCH_SIZE = 50;
    private const CHUNK_SIZE = 10;

    private Mailer $mailer;
    private string $fromAddress;
    private string $fromName;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $smtpHost = 'localhost',
        int $smtpPort = 587,
        string $username = '',
        string $password = '',
        string $encryption = 'tls'
    ) {
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s&auth_mode=plain',
            urlencode($username),
            urlencode($password),
            $smtpHost,
            $smtpPort,
            $encryption
        );

        $transport = Transport::fromDsn($dsn);
        $transport->setTimeout(self::SMTP_TIMEOUT);

        $this->mailer = new Mailer($transport);
        $this->fromAddress = $username;
        $this->fromName = 'System';
    }

    public function send(EmailMessage $message): bool
    {
        $attempts = 0;

        while ($attempts < self::SMTP_MAX_RETRIES) {
            try {
                $email = $this->buildEmail($message);

                $this->mailer->send($email);

                $this->logger->info('Email sent successfully', [
                    'to' => $message->getTo(),
                    'subject' => $message->getSubject(),
                    'attempts' => $attempts + 1,
                    'timeout' => self::SMTP_TIMEOUT,
                    'connection_timeout' => self::SMTP_CONNECTION_TIMEOUT,
                ]);

                return true;
            } catch (\Exception $e) {
                $attempts++;
                $this->logger->error('Failed to send email', [
                    'to' => $message->getTo(),
                    'subject' => $message->getSubject(),
                    'attempt' => $attempts,
                    'max_retries' => self::SMTP_MAX_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::SMTP_RETRY_DELAY,
                ]);

                if ($attempts < self::SMTP_MAX_RETRIES) {
                    usleep(self::SMTP_RETRY_DELAY * 1000 * $attempts);
                }
            }
        }

        return false;
    }

    public function sendBatch(array $messages): array
    {
        $results = [
            'sent' => 0,
            'failed' => 0,
            'details' => [],
        ];

        $chunks = array_chunk($messages, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $message) {
                $success = $this->send($message);

                if ($success) {
                    $results['sent']++;
                } else {
                    $results['failed']++;
                }

                $results['details'][] = [
                    'to' => $message->getTo(),
                    'subject' => $message->getSubject(),
                    'success' => $success,
                ];
            }

            if (count($chunks) > 1) {
                usleep(100000);
            }
        }

        $this->logger->info('Batch email send completed', [
            'total' => count($messages),
            'sent' => $results['sent'],
            'failed' => $results['failed'],
            'batch_size' => self::BATCH_SIZE,
            'chunk_size' => self::CHUNK_SIZE,
            'pool_size' => self::SMTP_POOL_SIZE,
        ]);

        return $results;
    }

    public function sendTemplate(string $to, string $subject, string $template, array $variables): bool
    {
        $message = new EmailMessage();
        $message->setTo($to);
        $message->setSubject($subject);
        $message->setHtmlBody($this->renderTemplate($template, $variables));

        return $this->send($message);
    }

    private function buildEmail(EmailMessage $message): Email
    {
        $email = (new Email())
            ->from($this->fromAddress)
            ->to($message->getTo())
            ->subject($message->getSubject())
            ->text($message->getTextBody());

        if ($message->getHtmlBody() !== null) {
            $email->html($message->getHtmlBody());
        }

        foreach ($message->getAttachments() as $attachment) {
            $email->attach($attachment['content'], $attachment['name'], $attachment['mime']);
        }

        foreach ($message->getHeaders() as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $email;
    }

    private function renderTemplate(string $template, array $variables): string
    {
        $html = $template;

        foreach ($variables as $key => $value) {
            $html = str_replace('{{' . $key . '}}', htmlspecialchars($value), $html);
        }

        return $html;
    }
}
