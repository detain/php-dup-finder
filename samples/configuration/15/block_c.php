<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Exceptions\SmsDeliveryException;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

final class TwilioSmsService
{
    private const SMS_TIMEOUT = 15;
    private const SMS_CONNECT_TIMEOUT = 5;
    private const SMS_MAX_RETRIES = 3;
    private const SMS_RETRY_DELAY = 500;
    private const SMS_POOL_SIZE = 10;
    private const SMS_KEEPALIVE = 30;
    private const BATCH_SIZE = 100;
    private const CHUNK_SIZE = 20;
    private const MAX_MESSAGE_LENGTH = 1600;
    private const SEGMENT_LENGTH = 160;

    private Client $httpClient;
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $accountSid,
        string $authToken,
        string $fromNumber
    ) {
        $this->accountSid = $accountSid;
        $this->authToken = $authToken;
        $this->fromNumber = $fromNumber;

        $this->httpClient = new Client([
            'base_uri' => 'https://api.twilio.com/2010-04-01/',
            'timeout' => self::SMS_TIMEOUT,
            'connect_timeout' => self::SMS_CONNECT_TIMEOUT,
            'pool_size' => self::SMS_POOL_SIZE,
            'keepalive' => self::SMS_KEEPALIVE,
            'auth' => [$accountSid, $authToken],
        ]);
    }

    public function send(SmsMessage $message): bool
    {
        $attempts = 0;

        while ($attempts < self::SMS_MAX_RETRIES) {
            try {
                $segments = $this->segmentMessage($message->getBody());
                $sentSegments = 0;

                foreach ($segments as $segment) {
                    $result = $this->sendSingleSegment(
                        $message->getTo(),
                        $segment,
                        count($segments) > 1 ? $sentSegments + 1 : null
                    );

                    if ($result) {
                        $sentSegments++;
                    }
                }

                $this->logger->info('SMS sent successfully', [
                    'to' => $message->getTo(),
                    'segments' => count($segments),
                    'sent_segments' => $sentSegments,
                    'attempts' => $attempts + 1,
                    'timeout' => self::SMS_TIMEOUT,
                    'connect_timeout' => self::SMS_CONNECT_TIMEOUT,
                    'max_length' => self::MAX_MESSAGE_LENGTH,
                ]);

                return $sentSegments === count($segments);
            } catch (\Exception $e) {
                $attempts++;
                $this->logger->warning('Failed to send SMS', [
                    'to' => $message->getTo(),
                    'attempt' => $attempts,
                    'max_retries' => self::SMS_MAX_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::SMS_RETRY_DELAY,
                ]);

                if ($attempts < self::SMS_MAX_RETRIES) {
                    usleep(self::SMS_RETRY_DELAY * 1000 * $attempts);
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
                    'body_length' => strlen($message->getBody()),
                    'success' => $success,
                ];
            }

            if (count($chunks) > 1) {
                usleep(100000);
            }
        }

        $this->logger->info('Batch SMS send completed', [
            'total' => count($messages),
            'sent' => $results['sent'],
            'failed' => $results['failed'],
            'batch_size' => self::BATCH_SIZE,
            'chunk_size' => self::CHUNK_SIZE,
            'pool_size' => self::SMS_POOL_SIZE,
        ]);

        return $results;
    }

    private function sendSingleSegment(string $to, string $body, ?int $segmentNum = null): bool
    {
        $params = [
            'To' => $to,
            'From' => $this->fromNumber,
            'Body' => $body,
        ];

        if ($segmentNum !== null) {
            $params['StatusCallback'] = sprintf(
                'https://example.com/sms/status?segment=%d',
                $segmentNum
            );
        }

        $response = $this->httpClient->post(
            'Accounts/' . $this->accountSid . '/Messages.json',
            ['form_params' => $params]
        );

        $result = json_decode($response->getBody()->getContents(), true);

        return ($result['status'] ?? '') !== 'failed';
    }

    private function segmentMessage(string $body): array
    {
        if (strlen($body) <= self::SEGMENT_LENGTH) {
            return [$body];
        }

        $segments = [];
        $remaining = $body;
        $segmentRef = time() % 255;

        while (strlen($remaining) > 0) {
            $segment = substr($remaining, 0, self::SEGMENT_LENGTH - 6);
            $remaining = substr($remaining, strlen($segment));

            $segments[] = $segment;
        }

        return $segments;
    }
}
