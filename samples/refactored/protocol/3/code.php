<?php
declare(strict_types=1);

namespace Acme\Webhooks;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class SignedWebhookSender
{
    /**
     * @param array{ts:string,nonce:string,sig:string} $headerNames
     * @param callable|null $signatureFormatter
     */
    public function __construct(
        private readonly Client $http,
        private readonly LoggerInterface $logger,
        private readonly string $secret,
        private readonly array $headerNames,
        private readonly string $userAgent,
        private readonly mixed $signatureFormatter = null
    ) {
    }

    public function dispatch(string $url, array $event): void
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $rawSig = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $this->secret);
        $sigHeader = $this->signatureFormatter ? ($this->signatureFormatter)($rawSig) : $rawSig;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->http->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        $this->headerNames['ts'] => $timestamp,
                        $this->headerNames['nonce'] => $nonce,
                        $this->headerNames['sig'] => $sigHeader,
                        'User-Agent' => $this->userAgent,
                    ],
                    'body' => $body,
                    'timeout' => 10.0,
                ]);
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $this->logger->info($this->userAgent . ' accepted', ['status' => $status]);
                    return;
                }
                if ($status >= 500) {
                    usleep((int) (500000 * $attempt));
                    continue;
                }
                throw new \RuntimeException($this->userAgent . ' HTTP ' . $status);
            } catch (\Throwable $e) {
                if ($attempt >= 3) {
                    throw new \RuntimeException($this->userAgent . ' delivery failed', 0, $e);
                }
                usleep((int) (500000 * $attempt));
            }
        }
    }
}
