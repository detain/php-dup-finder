<?php

declare(strict_types=1);

namespace App\Http\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class S3StorageClient
{
    private const CLIENT_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 200;
    private const POOL_SIZE = 10;
    private const KEEP_ALIVE = 60;
    private const MAX_redirects = 5;
    private const EXPECT_HEADER = '100-continue';
    private const BUFFER_SIZE = 8192;

    private Client $httpClient;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $endpoint,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $bucket
    ) {
        $this->httpClient = $this->createHttpClient();
    }

    private function createHttpClient(): Client
    {
        return new Client([
            'base_uri' => $this->endpoint,
            'timeout' => self::CLIENT_TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'max_redirects' => self::MAX_REDIRECTs,
            'http_errors' => true,
            'verify' => true,
            'pool_size' => self::POOL_SIZE,
            'keep_alive' => self::KEEP_ALIVE,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/octet-stream',
                'X-Amz-Content-Sha256' => 'required',
                'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            ],
        ]);
    }

    public function upload(string $key, string $data, array $metadata = []): bool
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $headers = $this->generateAuthHeaders('PUT', $key, $data);

                $response = $this->httpClient->put(
                    $this->getObjectUri($key),
                    [
                        'body' => $data,
                        'headers' => array_merge($headers, [
                            'Content-Length' => strlen($data),
                            'Expect' => self::EXPECT_HEADER,
                        ]),
                        'timeout' => self::CLIENT_TIMEOUT,
                    ]
                );

                $this->logger->info('S3 upload successful', [
                    'key' => $key,
                    'bucket' => $this->bucket,
                    'size' => strlen($data),
                    'attempts' => $attempts + 1,
                    'region' => $this->region,
                ]);

                return $response->getStatusCode() === 200;
            } catch (GuzzleException $e) {
                $attempts++;
                $this->logger->error('S3 upload failed', [
                    'key' => $key,
                    'bucket' => $this->bucket,
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_DELAY,
                ]);

                if ($attempts < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY * 1000 * $attempts);
                    $this->httpClient = $this->createHttpClient();
                }
            }
        }

        return false;
    }

    public function download(string $key): ?string
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $headers = $this->generateAuthHeaders('GET', $key);

                $response = $this->httpClient->get(
                    $this->getObjectUri($key),
                    [
                        'headers' => $headers,
                        'timeout' => self::CLIENT_TIMEOUT,
                        'buffer_size' => self::BUFFER_SIZE,
                    ]
                );

                $body = $response->getBody()->getContents();

                $this->logger->info('S3 download successful', [
                    'key' => $key,
                    'bucket' => $this->bucket,
                    'size' => strlen($body),
                    'attempts' => $attempts + 1,
                ]);

                return $body;
            } catch (GuzzleException $e) {
                $attempts++;
                $this->logger->error('S3 download failed', [
                    'key' => $key,
                    'bucket' => $this->bucket,
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_DELAY,
                ]);

                if ($attempts < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY * 1000 * $attempts);
                }
            }
        }

        return null;
    }

    public function delete(string $key): bool
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $headers = $this->generateAuthHeaders('DELETE', $key);

                $response = $this->httpClient->delete(
                    $this->getObjectUri($key),
                    [
                        'headers' => $headers,
                        'timeout' => self::CLIENT_TIMEOUT,
                    ]
                );

                $this->logger->info('S3 delete successful', [
                    'key' => $key,
                    'bucket' => $this->bucket,
                    'attempts' => $attempts + 1,
                ]);

                return $response->getStatusCode() === 204;
            } catch (GuzzleException $e) {
                $attempts++;
                $this->logger->error('S3 delete failed', [
                    'key' => $key,
                    'bucket' => $this->bucket,
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_DELAY,
                ]);

                if ($attempts < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY * 1000 * $attempts);
                }
            }
        }

        return false;
    }

    public function getObjectUri(string $key): string
    {
        return sprintf('%s/%s/%s', $this->endpoint, $this->bucket, ltrim($key, '/'));
    }

    private function generateAuthHeaders(string $method, string $key, ?string $payload = null): array
    {
        $date = gmdate('YmdHis');
        $dateStamp = gmdate('Ymd');
        $service = 's3';
        $region = $this->region;

        $hashedPayload = hash('sha256', $payload ?? '');
        $canonicalHeaders = sprintf(
            "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n",
            parse_url($this->endpoint, PHP_URL_HOST),
            $hashedPayload,
            $date
        );

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = sprintf(
            "%s\n/%s/%s\n\n%s\n%s",
            $method,
            $this->bucket,
            ltrim($key, '/'),
            $canonicalHeaders,
            $signedHeaders
        );

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = sprintf('%s/%s/%s/aws4_request', $dateStamp, $region, $service);
        $stringToSign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $date,
            $credentialScope,
            hash('sha256', $canonicalRequest)
        );

        $kSecret = 'AWS4' . $this->secretKey;
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return [
            'X-Amz-Date' => $date,
            'X-Amz-Content-Sha256' => $hashedPayload,
            'Authorization' => sprintf(
                '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $algorithm,
                $this->accessKey,
                $credentialScope,
                $signedHeaders,
                $signature
            ),
        ];
    }
}
