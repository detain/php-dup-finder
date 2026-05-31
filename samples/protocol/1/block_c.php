<?php
declare(strict_types=1);

namespace Acme\Sms\Twilio;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

final class TwilioSmsClient
{
    public function __construct(
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $accountSid
    ) {
    }

    public function sendSms(string $to, string $from, string $body): array
    {
        $attempt = 0;
        $maxAttempts = 4;
        $payloadJson = json_encode([
            'To' => $to,
            'From' => $from,
            'Body' => $body,
        ], JSON_THROW_ON_ERROR);

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $this->accountSid . '/Messages.json';

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $response = $this->http->request('POST', $url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'User-Agent' => 'acme-twilio/1.0',
                    ],
                    'body' => $payloadJson,
                    'timeout' => 15.0,
                    'connect_timeout' => 5.0,
                ]);
                $status = $response->getStatusCode();
                $payload = (string) $response->getBody();
                if ($status >= 200 && $status < 300) {
                    return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                }
                if ($status >= 500 && $attempt < $maxAttempts) {
                    usleep((int) (250000 * (2 ** ($attempt - 1))));
                    continue;
                }
                $this->logger->error('Twilio non-2xx', ['status' => $status, 'body' => $payload]);
                throw new \RuntimeException('Twilio error: HTTP ' . $status);
            } catch (RequestException $e) {
                $this->logger->warning('Twilio transport failure', ['attempt' => $attempt, 'err' => $e->getMessage()]);
                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException('Twilio unreachable', 0, $e);
                }
                usleep((int) (250000 * (2 ** ($attempt - 1))));
            }
        }
        throw new \RuntimeException('Twilio retry exhausted');
    }
}
