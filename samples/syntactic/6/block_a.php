<?php
declare(strict_types=1);

namespace Acme\Web;

final class UrlBuilder
{
    private function __construct(
        private string $scheme,
        private string $host,
        private int $port,
        private string $path,
        private array $query,
    ) {
    }

    public static function for(string $host): self
    {
        return new self(
            scheme: 'https',
            host:   $host,
            port:   443,
            path:   '/',
            query:  [],
        );
    }

    public function withScheme(string $scheme): self
    {
        $copy = clone $this;
        $copy->scheme = $scheme;
        return $copy;
    }

    public function withPort(int $port): self
    {
        $copy = clone $this;
        $copy->port = $port;
        return $copy;
    }

    public function withPath(string $path): self
    {
        $copy = clone $this;
        $copy->path = $path;
        return $copy;
    }

    public function withQuery(array $query): self
    {
        $copy = clone $this;
        $copy->query = $query;
        return $copy;
    }

    public function toString(): string
    {
        $qs = $this->query === [] ? '' : '?' . http_build_query($this->query);
        return sprintf('%s://%s:%d%s%s', $this->scheme, $this->host, $this->port, $this->path, $qs);
    }
}
