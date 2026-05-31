<?php

declare(strict_types=1);

namespace Acme\Integrations\Shopify;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;

final class ShopifyOrderLister
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly LoggerInterface $logger,
        private readonly string $shop,
        private readonly string $accessToken,
    ) {
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(string $status): \Generator
    {
        $cursor = null;
        $page = 0;

        do {
            $page++;
            $url = "https://{$this->shop}.myshopify.com/admin/api/2024-01/orders.json?status={$status}&limit=250"
                . ($cursor !== null ? "&page_info={$cursor}" : '');
            $req = $this->requests->createRequest('GET', $url)
                ->withHeader('X-Shopify-Access-Token', $this->accessToken)
                ->withHeader('Accept', 'application/json');

            $res = $this->http->sendRequest($req);
            $body = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);

            foreach ($body['orders'] as $order) {
                yield $order;
            }

            $linkHeader = $res->getHeaderLine('Link');
            $cursor = $this->extractPageInfo($linkHeader);
            $this->logger->debug('shopify page fetched', ['page' => $page, 'count' => count($body['orders']), 'next' => $cursor]);
        } while ($cursor !== null);
    }

    private function extractPageInfo(string $link): ?string
    {
        if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>;\s*rel="next"/', $link, $m) === 1) {
            return $m[1];
        }
        return null;
    }
}
