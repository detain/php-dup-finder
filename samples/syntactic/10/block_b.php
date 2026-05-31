<?php
declare(strict_types=1);

namespace Acme\Http;

final class HttpRequestBuilder
{
    private string $method = 'GET';
    private string $url = '';
    private array $query = [];
    private array $headers = [];
    private string $payload = '';

    public function method(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function url(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function query(array $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function headers(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    public function payload(string $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    public function build(): HttpRequest
    {
        return new HttpRequest(
            method:  $this->method,
            url:     $this->url,
            query:   $this->query,
            headers: $this->headers,
            payload: $this->payload,
        );
    }
}
