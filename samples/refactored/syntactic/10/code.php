<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * Generic fluent builder. Stores key/value pairs against a known schema and
 * instantiates the target DTO via a factory closure. Each former setter is now
 * a one-liner forwarder, so there is no repeated `return $this` scaffold.
 *
 * @template T
 */
final class SchemaFluentBuilder
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $defaults */
    /** @param callable(array<string,mixed>):T $factory */
    public function __construct(
        array $defaults,
        private readonly mixed $factory,
    ) {
        $this->values = $defaults;
    }

    public function set(string $field, mixed $value): self
    {
        $this->values[$field] = $value;
        return $this;
    }

    /** @return T */
    public function build(): mixed
    {
        return ($this->factory)($this->values);
    }
}

/* Domain-specific facades become thin: define defaults and a single factory
 * closure, then expose `name(...)` shortcuts as `->set('field', value)`. The
 * five lines of `return $this` boilerplate per setter disappear.
 *
 *  $email = (new SchemaFluentBuilder(
 *      ['subject' => '', 'from' => '', 'to' => [], 'headers' => [], 'body' => ''],
 *      fn(array $v) => new EmailMessage(...$v),
 *  ))
 *      ->set('subject', 'Hello')
 *      ->set('from',    'a@b.test')
 *      ->set('to',      ['x@y.test'])
 *      ->build();
 */
