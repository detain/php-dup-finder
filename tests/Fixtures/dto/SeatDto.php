<?php
declare(strict_types=1);

namespace Phpdup\Fixtures\Dto;

class SeatDto
{
    private int $id;
    private string $name;
    private float $amount;

    public function __construct(int $id, string $name, float $amount)
    {
        $this->id = $id;
        $this->name = $name;
        $this->amount = $amount;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'amount' => $this->amount,
        ];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }
}
