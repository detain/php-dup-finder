<?php

declare(strict_types=1);

namespace Acme\Integrations\Stripe;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;

final class StripeChargeLister
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly LoggerInterface $logger,
        private readonly string $secretKey,
    ) {
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(string $customerId): \Generator
    {
        $cursor = null;
        $page = 0;

        do {
            $page++;
            $url = "https://api.stripe.com/v1/charges?customer={$customerId}&limit=100" . ($cursor !== null ? "&starting_after={$cursor}" : '');
            $req = $this->requests->createRequest('GET', $url)
                ->withHeader('Authorization', "Bearer {$this->secretKey}")
                ->withHeader('Accept', 'application/json');

            $res = $this->http->sendRequest($req);
            $body = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);

            foreach ($body['data'] as $charge) {
                yield $charge;
            }

            $cursor = ($body['has_more'] ?? false)
                ? (string) end($body['data'])['id']
                : null;
            $this->logger->debug('stripe page fetched', ['page' => $page, 'count' => count($body['data']), 'next' => $cursor]);
        } while ($cursor !== null);
    }
}
