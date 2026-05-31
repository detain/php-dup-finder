<?php
declare(strict_types=1);

namespace Acme\Api\Resources;

use Acme\Locale\Translator;

/**
 * @template T of object
 */
interface ResourceSchema
{
    public function type(): string;
    /** @param T $entity */
    public function id(object $entity): string;
    /** @param T $entity @return array<string,mixed> */
    public function attributes(object $entity, string $locale, Translator $t): array;
    /** @param T $entity @return array<string,array<string,mixed>> */
    public function relationships(object $entity, string $baseUrl): array;
    /** @param T $entity @return array<string,string> */
    public function links(object $entity, string $baseUrl): array;
    /** @param T $entity @return array<string,mixed> */
    public function lifecycleMeta(object $entity): array;
}

final class JsonApiSerializer
{
    public function __construct(
        private readonly Translator $translator,
        private readonly string $baseUrl
    ) {
    }

    /** @return array<string,mixed> */
    public function serialize(ResourceSchema $schema, object $entity, string $locale): array
    {
        $payload = [
            'type' => $schema->type(),
            'id'   => $schema->id($entity),
            'attributes' => $schema->attributes($entity, $locale, $this->translator),
            'relationships' => $schema->relationships($entity, $this->baseUrl),
            'links' => $schema->links($entity, $this->baseUrl),
            'meta' => [
                'locale'    => $locale,
                'version'   => 'v2',
                'cacheable' => true,
            ],
        ];
        $extra = $schema->lifecycleMeta($entity);
        if ($extra !== []) {
            $payload['attributes'] = array_merge($payload['attributes'], $extra['attributes'] ?? []);
            $payload['meta']       = array_merge($payload['meta'], $extra['meta'] ?? []);
        }
        return $payload;
    }
}
