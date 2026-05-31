<?php
declare(strict_types=1);

namespace Acme\Issues\Linear;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

final class LinearIssueClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey
    ) {
    }

    public function issueByIdentifier(string $identifier): array
    {
        $query = 'query($id:String!){ issue(id:$id){ id title state{name} } }';
        $body = json_encode(['query' => $query, 'variables' => ['id' => $identifier]], JSON_THROW_ON_ERROR);
        $url = 'https://api.linear.app/graphql';

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('Linear non-2xx', ['status' => $status, 'body' => $payload]);
            throw new \RuntimeException('Linear HTTP ' . $status);
        }
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!empty($decoded['errors'])) {
            foreach ($decoded['errors'] as $err) {
                $this->logger->warning('Linear GraphQL error', ['err' => $err]);
            }
            throw new \RuntimeException('Linear GraphQL errors: ' . $decoded['errors'][0]['message']);
        }
        return $decoded['data'] ?? [];
    }
}
