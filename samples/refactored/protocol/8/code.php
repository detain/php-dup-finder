<?php
declare(strict_types=1);

namespace Acme\Http\Pagination;

use Symfony\Component\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

final class CursorPaginatedFetcher
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $tag
    ) {
    }

    /**
     * @param callable(string|null $cursor): array{method:string,url:string,options:array} $requestBuilder
     * @param callable(array): array{0:array,1:?string} $extractor returns [rows, nextCursor]
     */
    public function fetchAll(callable $requestBuilder, callable $extractor): array
    {
        $cursor = null;
        $all = [];
        $page = 0;
        do {
            $page++;
            if ($page > 200) {
                throw new \RuntimeException($this->tag . ' pagination runaway');
            }
            $req = $requestBuilder($cursor);
            $resp = $this->http->request($req['method'], $req['url'], $req['options']);
            $status = $resp->getStatusCode();
            $raw = $resp->getContent(false);
            if ($status < 200 || $status >= 300) {
                $this->logger->error($this->tag . ' list failed', ['status' => $status, 'body' => $raw]);
                throw new \RuntimeException($this->tag . ' HTTP ' . $status);
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            [$rows, $cursor] = $extractor($decoded);
            foreach ($rows as $row) {
                $all[] = $row;
            }
        } while ($cursor !== null);
        return $all;
    }
}
