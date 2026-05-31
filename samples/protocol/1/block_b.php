<?php
declare(strict_types=1);

namespace Acme\Mail\SendGrid;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

final class SendGridMailClient
{
    public function __construct(
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey
    ) {
    }

    public function sendMail(string $to, string $subject, string $html): array
    {
        $attempt = 0;
        $maxAttempts = 4;
        $body = json_encode([
            'personalizations' => [['to' => [['email' => $to]]]],
            'from' => ['email' => 'noreply@acme.test'],
            'subject' => $subject,
            'content' => [['type' => 'text/html', 'value' => $html]],
        ], JSON_THROW_ON_ERROR);

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $response = $this->http->request('POST', 'https://api.sendgrid.com/v3/mail/send', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'User-Agent' => 'acme-sendgrid/1.0',
                    ],
                    'body' => $body,
                    'timeout' => 15.0,
                    'connect_timeout' => 5.0,
                ]);
                $status = $response->getStatusCode();
                $payload = (string) $response->getBody();
                if ($status >= 200 && $status < 300) {
                    return $payload === '' ? [] : json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                }
                if ($status >= 500 && $attempt < $maxAttempts) {
                    usleep((int) (250000 * (2 ** ($attempt - 1))));
                    continue;
                }
                $this->logger->error('SendGrid non-2xx', ['status' => $status, 'body' => $payload]);
                throw new \RuntimeException('SendGrid error: HTTP ' . $status);
            } catch (RequestException $e) {
                $this->logger->warning('SendGrid transport failure', ['attempt' => $attempt, 'err' => $e->getMessage()]);
                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException('SendGrid unreachable', 0, $e);
                }
                usleep((int) (250000 * (2 ** ($attempt - 1))));
            }
        }
        throw new \RuntimeException('SendGrid retry exhausted');
    }
}
