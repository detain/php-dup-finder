<?php
declare(strict_types=1);

namespace App\Http;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class FileUploadClient
{
    private HttpClientInterface $httpClient;
    private ConfigManager $config;
    private LoggerInterface $logger;
    private int $maxRetries = 3;
    private int $baseDelayMs = 100;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->httpClient = HttpClient::create();
    }

    public function upload(string $url, string $filePath, array $headers = []): array
    {
        return $this->uploadWithRetry($url, $filePath, $headers);
    }

    private function uploadWithRetry(
        string $url,
        string $filePath,
        array $headers = [],
        int $attempt = 0
    ): array {
        try {
            $fileContent = file_get_contents($filePath);
            
            if ($fileContent === false) {
                throw new \RuntimeException("Failed to read file: {$filePath}");
            }
            
            $options = [
                'headers' => array_merge($headers, [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => strlen($fileContent),
                ]),
                'body' => $fileContent,
            ];
            
            $response = $this->httpClient->request('POST', $url, $options);
            
            if ($response->getStatusCode() >= 500 && $attempt < $this->maxRetries) {
                return $this->handleRetry($url, $filePath, $headers, $attempt, 'server_error');
            }
            
            if ($response->getStatusCode() === 429 && $attempt < $this->maxRetries) {
                return $this->handleRetry($url, $filePath, $headers, $attempt, 'rate_limit');
            }
            
            if ($response->getStatusCode() >= 400) {
                return $response->toArray();
            }
            
            return $response->toArray();
            
        } catch (TransportExceptionInterface $e) {
            if ($attempt < $this->maxRetries) {
                return $this->handleRetry($url, $filePath, $headers, $attempt, 'transport');
            }
            
            $this->logger->error('File upload failed after retries', [
                'url' => $url,
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    private function handleRetry(
        string $url,
        string $filePath,
        array $headers,
        int $attempt,
        string $reason
    ): array {
        $delay = $this->calculateBackoffDelay($attempt);
        
        $this->logger->warning('File upload retrying', [
            'url' => $url,
            'file' => $filePath,
            'attempt' => $attempt + 1,
            'delay_ms' => $delay,
            'reason' => $reason,
        ]);
        
        usleep($delay * 1000);
        
        return $this->uploadWithRetry($url, $filePath, $headers, $attempt + 1);
    }

    private function calculateBackoffDelay(int $attempt): int
    {
        $exponentialDelay = $this->baseDelayMs * pow(2, $attempt);
        $jitter = random_int(0, (int)($exponentialDelay * 0.1));
        return $exponentialDelay + $jitter;
    }
}
