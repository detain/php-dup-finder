<?php

declare(strict_types=1);

namespace Acme\Common\Handler;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @template TEntity of object
 * @template TEvent of object
 */
interface ResourceValidator
{
    /** @return array<int, string> */
    public function validate(array $input): array;
}

interface ResourceNormalizer
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array;
}

interface ResourceRepository
{
    /** @param array<string, mixed> $data */
    public function insert(array $data): object;
}

final class CreateResourceHandler
{
    public function __construct(
        private readonly ResourceValidator $validator,
        private readonly ResourceNormalizer $normalizer,
        private readonly ResourceRepository $repo,
        private readonly EventDispatcherInterface $events,
        private readonly string $resourceLabel,
        private readonly string $ownerField,
        private readonly string $timestampField,
        /** @var callable(object, int): object */
        private $eventFactory,
        /** @var callable(object): array<string, mixed> */
        private $responseFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function handle(array $input, int $ownerId): array
    {
        $errors = $this->validator->validate($input);
        if ($errors !== []) {
            throw new \InvalidArgumentException("invalid {$this->resourceLabel} payload: " . implode(', ', $errors));
        }

        $normalized = $this->normalizer->normalize($input);
        $normalized[$this->ownerField] = $ownerId;
        $normalized[$this->timestampField] = new \DateTimeImmutable();

        $entity = $this->repo->insert($normalized);
        $this->events->dispatch(($this->eventFactory)($entity, $ownerId));

        return ($this->responseFactory)($entity);
    }
}
