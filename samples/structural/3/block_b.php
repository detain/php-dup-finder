<?php

declare(strict_types=1);

namespace Acme\Support\Handler;

use Acme\Support\Repository\TicketRepository;
use Acme\Support\Validator\TicketValidator;
use Acme\Support\Normalizer\TicketNormalizer;
use Acme\Support\Event\TicketOpened;
use Psr\EventDispatcher\EventDispatcherInterface;

final class OpenTicketHandler
{
    public function __construct(
        private readonly TicketValidator $validator,
        private readonly TicketNormalizer $normalizer,
        private readonly TicketRepository $repo,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, reference: string}
     */
    public function handle(array $input, int $requesterId): array
    {
        $errors = $this->validator->validate($input);
        if ($errors !== []) {
            throw new \InvalidArgumentException('invalid ticket payload: ' . implode(', ', $errors));
        }

        $normalized = $this->normalizer->normalize($input);
        $normalized['requester_id'] = $requesterId;
        $normalized['opened_at'] = new \DateTimeImmutable();

        $ticket = $this->repo->insert($normalized);

        $this->events->dispatch(new TicketOpened(
            $ticket->id(),
            $ticket->reference(),
            $requesterId,
        ));

        return [
            'id' => $ticket->id(),
            'reference' => $ticket->reference(),
        ];
    }
}
