<?php
declare(strict_types=1);

namespace Acme\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

final class BearerApiClient
{
    public function __construct(
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $userAgent,
        private readonly int $maxAttempts = 4
    ) {
    }

    public function postJson(string $url, array $body): array
    {
        $serialized = json_encode($body, JSON_THROW_ON_ERROR);
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = $this->http->request('POST', $url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'User-Agent' => $this->userAgent,
                    ],
                    'body' => $serialized,
                    'timeout' => 15.0,
                    'connect_timeout' => 5.0,
                ]);
                $status = $response->getStatusCode();
                $raw = (string) $response->getBody();
                if ($status >= 200 && $status < 300) {
                    return $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                }
                if ($status >= 500 && $attempt < $this->maxAttempts) {
                    usleep((int) (250000 * (2 ** ($attempt - 1))));
                    continue;
                }
                $this->logger->error($this->userAgent . ' non-2xx', ['status' => $status, 'body' => $raw]);
                throw new \RuntimeException($this->userAgent . ' HTTP ' . $status);
            } catch (RequestException $e) {
                $this->logger->warning($this->userAgent . ' transport', ['attempt' => $attempt, 'err' => $e->getMessage()]);
                if ($attempt >= $this->maxAttempts) {
                    throw new \RuntimeException($this->userAgent . ' unreachable', 0, $e);
                }
                usleep((int) (250000 * (2 ** ($attempt - 1))));
            }
        }
        throw new \RuntimeException($this->userAgent . ' retry exhausted');
    }
}
