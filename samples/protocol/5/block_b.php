<?php
declare(strict_types=1);

namespace Acme\Ecommerce\Shopify;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

final class ShopifyStorefrontClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
        private readonly string $shop,
        private readonly string $token
    ) {
    }

    public function product(string $handle): array
    {
        $query = 'query($handle:String!){ product(handle:$handle){ id title } }';
        $body = json_encode(['query' => $query, 'variables' => ['handle' => $handle]], JSON_THROW_ON_ERROR);
        $url = 'https://' . $this->shop . '.myshopify.com/api/2024-04/graphql.json';

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('X-Shopify-Storefront-Access-Token', $this->token)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('Shopify SF non-2xx', ['status' => $status, 'body' => $payload]);
            throw new \RuntimeException('Shopify HTTP ' . $status);
        }
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!empty($decoded['errors'])) {
            foreach ($decoded['errors'] as $err) {
                $this->logger->warning('Shopify GraphQL error', ['err' => $err]);
            }
            throw new \RuntimeException('Shopify GraphQL errors: ' . $decoded['errors'][0]['message']);
        }
        return $decoded['data'] ?? [];
    }
}
