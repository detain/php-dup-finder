<?php

declare(strict_types=1);

namespace Acme\Integrations\Sendgrid;

use Acme\Integrations\Http\HttpClient;
use Acme\Integrations\Sendgrid\Exception\SendgridThrottleException;
use Psr\Log\LoggerInterface;

final class TransactionalMailer
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $envelope
     */
    public function send(array $envelope): string
    {
        $attempt = 0;
        $maxAttempts = 4;
        $baseDelayMs = 750;
        $factor = 3.0;

        while (true) {
            try {
                $response = $this->http->post('/v3/mail/send', $envelope);

                return (string) ($response['message_id'] ?? '');
            } catch (SendgridThrottleException $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    $this->logger->error('sendgrid.mail.failed', [
                        'subject' => $envelope['subject'] ?? null,
                        'attempts' => $attempt,
                    ]);
                    throw $e;
                }

                $delay = (int) ($baseDelayMs * ($factor ** ($attempt - 1)));
                $jitter = random_int(0, (int) ($delay * 0.40));
                $sleepMs = $delay + $jitter;

                $this->logger->debug('sendgrid.mail.backoff', [
                    'attempt' => $attempt,
                    'sleep_ms' => $sleepMs,
                ]);

                usleep($sleepMs * 1000);
            }
        }
    }
}
