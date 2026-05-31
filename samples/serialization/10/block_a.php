<?php

declare(strict_types=1);

namespace App\Domain\Event;

class UserCreatedEvent
{
    private string $eventId;
    private string $userId;
    private string $email;
    private string $name;
    private array $roles;
    private DateTimeImmutable $occurredAt;
    private int $version = 1;

    public function __construct(
        string $eventId,
        string $userId,
        string $email,
        string $name,
        array $roles,
        DateTimeImmutable $occurredAt
    ) {
        $this->eventId = $eventId;
        $this->userId = $userId;
        $this->email = $email;
        $this->name = $name;
        $this->roles = $roles;
        $this->occurredAt = $occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'user.created',
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format('c'),
            'version' => $this->version,
            'payload' => [
                'user_id' => $this->userId,
                'email' => $this->email,
                'name' => $this->name,
                'roles' => $this->roles
            ],
            'metadata' => [
                'timestamp' => $this->occurredAt->getTimestamp(),
                'type' => 'user.created'
            ]
        ];
    }

    public static function fromArray(array $data): self
    {
        $payload = $data['payload'];

        return new self(
            $data['event_id'],
            $payload['user_id'],
            $payload['email'],
            $payload['name'],
            $payload['roles'],
            new DateTimeImmutable($data['occurred_at'])
        );
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if ($data === null) {
            throw new \InvalidArgumentException('Invalid JSON for UserCreatedEvent');
        }

        return self::fromArray($data);
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getEventType(): string
    {
        return 'user.created';
    }
}
