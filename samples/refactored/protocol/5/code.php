<?php
declare(strict_types=1);

namespace Acme\GraphQL;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

final class GraphQLClient
{
    /** @param array<string,string> $authHeaders */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
        private readonly string $url,
        private readonly array $authHeaders,
        private readonly string $tag
    ) {
    }

    public function query(string $document, array $variables): array
    {
        $body = json_encode(['query' => $document, 'variables' => $variables], JSON_THROW_ON_ERROR);
        $request = $this->requestFactory->createRequest('POST', $this->url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));
        foreach ($this->authHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            $this->logger->error($this->tag . ' non-2xx', ['status' => $status, 'body' => $payload]);
            throw new \RuntimeException($this->tag . ' HTTP ' . $status);
        }
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!empty($decoded['errors'])) {
            foreach ($decoded['errors'] as $err) {
                $this->logger->warning($this->tag . ' GraphQL error', ['err' => $err]);
            }
            throw new \RuntimeException($this->tag . ' GraphQL errors: ' . $decoded['errors'][0]['message']);
        }
        return $decoded['data'] ?? [];
    }
}
