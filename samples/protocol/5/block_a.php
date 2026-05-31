<?php
declare(strict_types=1);

namespace Acme\Content\Contentful;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

final class ContentfulCdaClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
        private readonly string $spaceId,
        private readonly string $token
    ) {
    }

    public function fetchEntry(string $id, array $variables): array
    {
        $query = 'query($id:String!){ entry(id:$id){ sys{id} fields } }';
        $body = json_encode(['query' => $query, 'variables' => $variables + ['id' => $id]], JSON_THROW_ON_ERROR);
        $url = 'https://graphql.contentful.com/content/v1/spaces/' . $this->spaceId;

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('Contentful CDA non-2xx', ['status' => $status, 'body' => $payload]);
            throw new \RuntimeException('Contentful HTTP ' . $status);
        }
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!empty($decoded['errors'])) {
            foreach ($decoded['errors'] as $err) {
                $this->logger->warning('Contentful GraphQL error', ['err' => $err]);
            }
            throw new \RuntimeException('Contentful GraphQL errors: ' . $decoded['errors'][0]['message']);
        }
        return $decoded['data'] ?? [];
    }
}
