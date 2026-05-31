<?php

declare(strict_types=1);

namespace Acme\Integrations\Twilio;

use Acme\Integrations\Http\HttpClient;
use Acme\Integrations\Twilio\Exception\TwilioRateLimitedException;
use Psr\Log\LoggerInterface;

final class SmsDispatcher
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendOtp(string $toNumber, string $code): string
    {
        $attempt = 0;
        $maxAttempts = 6;
        $baseDelayMs = 200;
        $factor = 2.0;

        while (true) {
            try {
                $response = $this->http->post('/2010-04-01/Messages.json', [
                    'To' => $toNumber,
                    'Body' => "Your code is {$code}",
                ]);

                return (string) $response['sid'];
            } catch (TwilioRateLimitedException $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    $this->logger->error('twilio.sms.gave_up', [
                        'to' => $toNumber,
                        'attempts' => $attempt,
                    ]);
                    throw $e;
                }

                $delay = (int) ($baseDelayMs * ($factor ** ($attempt - 1)));
                $jitter = random_int(0, (int) ($delay * 0.25));
                $sleepMs = $delay + $jitter;

                $this->logger->warning('twilio.sms.backoff', [
                    'attempt' => $attempt,
                    'sleep_ms' => $sleepMs,
                ]);

                usleep($sleepMs * 1000);
            }
        }
    }
}
