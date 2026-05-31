<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Exceptions\PushNotificationException;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

final class FcmPushNotificationService
{
    private const FCM_TIMEOUT = 20;
    private const FCM_CONNECT_TIMEOUT = 5;
    private const FCM_MAX_RETRIES = 3;
    private const FCM_RETRY_DELAY = 500;
    private const FCM_POOL_SIZE = 10;
    private const FCM_KEEPALIVE = 30;
    private const FCM_URL = 'https://fcm.googleapis.com/fcm/send';
    private const BATCH_SIZE = 100;
    private const CHUNK_SIZE = 20;

    private Client $httpClient;
    private string $serverKey;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $serverKey,
        string $projectId = ''
    ) {
        $this->serverKey = $serverKey;
        $this->httpClient = new Client([
            'base_uri' => self::FCM_URL,
            'timeout' => self::FCM_TIMEOUT,
            'connect_timeout' => self::FCM_CONNECT_TIMEOUT,
            'pool_size' => self::FCM_POOL_SIZE,
            'keepalive' => self::FCM_KEEPALIVE,
        ]);
    }

    public function send(PushNotification $notification): bool
    {
        $attempts = 0;

        while ($attempts < self::FCM_MAX_RETRIES) {
            try {
                $payload = $this->buildPayload($notification);

                $response = $this->httpClient->post(self::FCM_URL, [
                    'headers' => [
                        'Authorization' => 'key=' . $this->serverKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]);

                $result = json_decode($response->getBody()->getContents(), true);

                if (($result['success'] ?? 0) > 0) {
                    $this->logger->info('Push notification sent', [
                        'to' => $notification->getToken(),
                        'title' => $notification->getTitle(),
                        'attempts' => $attempts + 1,
                        'timeout' => self::FCM_TIMEOUT,
                        'connect_timeout' => self::FCM_CONNECT_TIMEOUT,
                    ]);

                    return true;
                }

                $failureReason = $result['results'][0]['error'] ?? 'Unknown error';
                throw new \RuntimeException('FCM error: ' . $failureReason);
            } catch (\Exception $e) {
                $attempts++;
                $this->logger->warning('Failed to send push notification', [
                    'to' => $notification->getToken(),
                    'title' => $notification->getTitle(),
                    'attempt' => $attempts,
                    'max_retries' => self::FCM_MAX_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::FCM_RETRY_DELAY,
                ]);

                if ($attempts < self::FCM_MAX_RETRIES) {
                    usleep(self::FCM_RETRY_DELAY * 1000 * $attempts);
                }
            }
        }

        return false;
    }

    public function sendBatch(array $notifications): array
    {
        $results = [
            'sent' => 0,
            'failed' => 0,
            'details' => [],
        ];

        $chunks = array_chunk($notifications, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $payload = [
                'registration_ids' => array_map(
                    fn(PushNotification $n) => $n->getToken(),
                    $chunk
                ),
                'notification' => [
                    'title' => $chunk[0]->getTitle(),
                    'body' => $chunk[0]->getBody(),
                ],
                'data' => $chunk[0]->getData(),
            ];

            try {
                $response = $this->httpClient->post(self::FCM_URL, [
                    'headers' => [
                        'Authorization' => 'key=' . $this->serverKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]);

                $result = json_decode($response->getBody()->getContents(), true);

                $successCount = $result['success'] ?? 0;
                $failureCount = $result['failure'] ?? 0;

                $results['sent'] += $successCount;
                $results['failed'] += $failureCount;

                foreach ($result['results'] ?? [] as $index => $r) {
                    $results['details'][] = [
                        'token' => $chunk[$index]->getToken(),
                        'success' => !isset($r['error']),
                        'error' => $r['error'] ?? null,
                    ];
                }
            } catch (\Exception $e) {
                $results['failed'] += count($chunk);
                $this->logger->error('Batch push notification failed', [
                    'batch_size' => count($chunk),
                    'error' => $e->getMessage(),
                    'pool_size' => self::FCM_POOL_SIZE,
                ]);
            }

            if (count($chunks) > 1) {
                usleep(50000);
            }
        }

        $this->logger->info('Batch push notification completed', [
            'total' => count($notifications),
            'sent' => $results['sent'],
            'failed' => $results['failed'],
            'batch_size' => self::BATCH_SIZE,
            'chunk_size' => self::CHUNK_SIZE,
        ]);

        return $results;
    }

    private function buildPayload(PushNotification $notification): array
    {
        $payload = [
            'to' => $notification->getToken(),
            'notification' => [
                'title' => $notification->getTitle(),
                'body' => $notification->getBody(),
                'sound' => $notification->getSound() ?? 'default',
            ],
            'data' => $notification->getData(),
        ];

        if ($notification->getBadge() !== null) {
            $payload['notification']['badge'] = $notification->getBadge();
        }

        if ($notification->getClickAction() !== null) {
            $payload['notification']['click_action'] = $notification->getClickAction();
        }

        return $payload;
    }
}
