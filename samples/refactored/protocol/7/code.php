<?php
declare(strict_types=1);

namespace Acme\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;

final class IdempotentPaymentClient
{
    /** @param array<string,string> $authHeaders */
    public function __construct(
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly string $url,
        private readonly array $authHeaders,
        private readonly string $tag
    ) {
    }

    public function charge(string $orderId, int $amountMinor, string $currency, array $body): array
    {
        $idempotencyKey = hash('sha256', $this->tag . '|' . $orderId . '|' . $amountMinor . '|' . $currency);
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $resp = $this->http->request('POST', $this->url, [
                    'headers' => $this->authHeaders + [
                        'Idempotency-Key' => $idempotencyKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                    'timeout' => 20.0,
                ]);
                $status = $resp->getStatusCode();
                $raw = (string) $resp->getBody();
                if (($status >= 200 && $status < 300) || $status === 409) {
                    if ($status === 409) {
                        $this->logger->info($this->tag . ' idempotency conflict', ['key' => $idempotencyKey]);
                    }
                    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                }
                if ($status >= 500 && $attempt < 5) {
                    usleep((int) (300000 * (2 ** ($attempt - 1))));
                    continue;
                }
                throw new \RuntimeException($this->tag . ' HTTP ' . $status . ': ' . $raw);
            } catch (ConnectException $e) {
                $this->logger->warning($this->tag . ' network blip', ['attempt' => $attempt, 'err' => $e->getMessage()]);
                if ($attempt >= 5) {
                    throw new \RuntimeException($this->tag . ' unreachable', 0, $e);
                }
                usleep((int) (300000 * (2 ** ($attempt - 1))));
            }
        }
        throw new \RuntimeException($this->tag . ' retry exhausted');
    }
}
