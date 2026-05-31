<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * Trait implementing the generic with*-by-clone pattern.
 *
 * Concrete classes promote their properties, define a static factory, and
 * use `$this->withField('name', $value)` instead of writing four near-identical
 * methods each.
 */
trait ImmutableWith
{
    /**
     * @return static
     */
    protected function withField(string $field, mixed $value): static
    {
        $copy = clone $this;
        $copy->{$field} = $value;
        return $copy;
    }
}

/* Example use in UrlBuilder:
 *
 *  use ImmutableWith;
 *
 *  public function withScheme(string $scheme): self   { return $this->withField('scheme', $scheme); }
 *  public function withPort(int $port): self          { return $this->withField('port', $port); }
 *  public function withPath(string $path): self       { return $this->withField('path', $path); }
 *  public function withQuery(array $query): self      { return $this->withField('query', $query); }
 *
 * The whole class collapses to declaring the promoted properties, the factory,
 * and one-line forwarders. The clone-and-mutate scaffold lives in the trait.
 */
