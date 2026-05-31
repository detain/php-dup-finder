<?php
declare(strict_types=1);

namespace Acme\Http\Client;

use Acme\Http\Request;
use Acme\Http\Internal\RequestBuilder;

final class WebhookRequestFactory
{
    public function __construct(
        private readonly string $signingSecret,
    ) {
    }

    public function deliveryRequest(string $url, string $payload, string $signature, string $traceId): Request
    {
        $builder = new RequestBuilder();
        // same token shape: 4 chained with* calls + build
        $builder
            ->withMethod('POST')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Signature', $signature)
            ->withBody($payload);

        $request = $builder->build();
        $request->setMetadata('url', $url);
        $request->setMetadata('trace', $traceId);
        return $request;
    }

    public function pingRequest(string $url, string $traceId): Request
    {
        return $this->deliveryRequest($url, '{"ping":true}', 'na', $traceId);
    }
}
