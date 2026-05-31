<?php

declare(strict_types=1);

namespace Acme\Integrations\Common;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class CursorPaginator
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(?string): string             $urlFor         cursor -> URL
     * @param callable(string): array<string, string> $headersFor   URL -> headers
     * @param callable(array, ResponseInterface): array{items: iterable, next: ?string} $extract
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginate(string $label, callable $urlFor, callable $headersFor, callable $extract): \Generator
    {
        $cursor = null;
        $page = 0;

        do {
            $page++;
            $url = $urlFor($cursor);
            $req = $this->requests->createRequest('GET', $url);
            foreach ($headersFor($url) as $k => $v) {
                $req = $req->withHeader($k, $v);
            }

            $res = $this->http->sendRequest($req);
            $body = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $result = $extract($body, $res);

            foreach ($result['items'] as $item) {
                yield $item;
            }

            $cursor = $result['next'];
            $this->logger->debug("{$label} page fetched", ['page' => $page, 'next' => $cursor]);
        } while ($cursor !== null);
    }
}
