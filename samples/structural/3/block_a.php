<?php

declare(strict_types=1);

namespace Acme\Forum\Handler;

use Acme\Forum\Repository\PostRepository;
use Acme\Forum\Validator\PostValidator;
use Acme\Forum\Normalizer\PostNormalizer;
use Acme\Forum\Event\PostCreated;
use Psr\EventDispatcher\EventDispatcherInterface;

final class CreatePostHandler
{
    public function __construct(
        private readonly PostValidator $validator,
        private readonly PostNormalizer $normalizer,
        private readonly PostRepository $repo,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, slug: string}
     */
    public function handle(array $input, int $authorId): array
    {
        $errors = $this->validator->validate($input);
        if ($errors !== []) {
            throw new \InvalidArgumentException('invalid post payload: ' . implode(', ', $errors));
        }

        $normalized = $this->normalizer->normalize($input);
        $normalized['author_id'] = $authorId;
        $normalized['created_at'] = new \DateTimeImmutable();

        $post = $this->repo->insert($normalized);

        $this->events->dispatch(new PostCreated(
            $post->id(),
            $post->slug(),
            $authorId,
        ));

        return [
            'id' => $post->id(),
            'slug' => $post->slug(),
        ];
    }
}
