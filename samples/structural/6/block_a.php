<?php

declare(strict_types=1);

namespace Acme\Integrations\Github;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;

final class GithubRepoLister
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly LoggerInterface $logger,
        private readonly string $token,
    ) {
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(string $org): \Generator
    {
        $cursor = null;
        $page = 0;

        do {
            $page++;
            $url = "https://api.github.com/orgs/{$org}/repos?per_page=100" . ($cursor !== null ? "&page={$cursor}" : '');
            $req = $this->requests->createRequest('GET', $url)
                ->withHeader('Authorization', "Bearer {$this->token}")
                ->withHeader('Accept', 'application/vnd.github+json');

            $res = $this->http->sendRequest($req);
            $body = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);

            foreach ($body as $repo) {
                yield $repo;
            }

            $link = $res->getHeaderLine('Link');
            $cursor = $this->parseNextPage($link);
            $this->logger->debug('github page fetched', ['page' => $page, 'count' => count($body), 'next' => $cursor]);
        } while ($cursor !== null);
    }

    private function parseNextPage(string $link): ?int
    {
        if (preg_match('/<[^>]*[?&]page=(\d+)[^>]*>;\s*rel="next"/', $link, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }
}
