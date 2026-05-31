<?php
declare(strict_types=1);

namespace Acme\Webhooks\BitBucket;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class BitBucketEventSender
{
    public function __construct(
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly string $secret
    ) {
    }

    public function dispatch(string $url, array $event): void
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $stringToSign = $timestamp . "\n" . $nonce . "\n" . $body;
        $signature = hash_hmac('sha256', $stringToSign, $this->secret);

        $attempt = 0;
        while ($attempt < 3) {
            $attempt++;
            try {
                $response = $this->http->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Bitbucket-Timestamp' => $timestamp,
                        'X-Bitbucket-Nonce' => $nonce,
                        'X-Bitbucket-Signature' => $signature,
                        'User-Agent' => 'acme-bitbucket-webhook/1.0',
                    ],
                    'body' => $body,
                    'timeout' => 10.0,
                ]);
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $this->logger->info('BitBucket webhook accepted', ['status' => $status]);
                    return;
                }
                if ($status >= 500) {
                    usleep((int) (500000 * $attempt));
                    continue;
                }
                $this->logger->error('BitBucket webhook rejected', ['status' => $status]);
                throw new \RuntimeException('BitBucket webhook HTTP ' . $status);
            } catch (\Throwable $e) {
                if ($attempt >= 3) {
                    throw new \RuntimeException('BitBucket webhook delivery failed', 0, $e);
                }
                usleep((int) (500000 * $attempt));
            }
        }
    }
}
