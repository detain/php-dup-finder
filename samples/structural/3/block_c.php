<?php

declare(strict_types=1);

namespace Acme\Catalog\Handler;

use Acme\Catalog\Repository\ReviewRepository;
use Acme\Catalog\Validator\ReviewValidator;
use Acme\Catalog\Normalizer\ReviewNormalizer;
use Acme\Catalog\Event\ReviewSubmitted;
use Psr\EventDispatcher\EventDispatcherInterface;

final class SubmitReviewHandler
{
    public function __construct(
        private readonly ReviewValidator $validator,
        private readonly ReviewNormalizer $normalizer,
        private readonly ReviewRepository $repo,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, public_id: string}
     */
    public function handle(array $input, int $reviewerId): array
    {
        $errors = $this->validator->validate($input);
        if ($errors !== []) {
            throw new \InvalidArgumentException('invalid review payload: ' . implode(', ', $errors));
        }

        $normalized = $this->normalizer->normalize($input);
        $normalized['reviewer_id'] = $reviewerId;
        $normalized['submitted_at'] = new \DateTimeImmutable();

        $review = $this->repo->insert($normalized);

        $this->events->dispatch(new ReviewSubmitted(
            $review->id(),
            $review->publicId(),
            $reviewerId,
        ));

        return [
            'id' => $review->id(),
            'public_id' => $review->publicId(),
        ];
    }
}
