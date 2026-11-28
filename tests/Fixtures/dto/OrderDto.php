<?php
declare(strict_types=1);

/*
 * Item 6.2 motivating shapes (synthetic stand-in for the phlix corpus
 * described in improve_logic.md §3.5 ranks #2/#3): DTO serializers and
 * route tables built from UNRELATED classes that share field names —
 * the shape whose AST-node mass used to buy them page-one slots.
 * Under --sort=score the declarative demotion must bury them while the
 * predicate triplicate (real logic) holds the first page.
 */

namespace Phpdup\Fixtures\Dto;

class OrderDto
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
