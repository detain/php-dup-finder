<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Http\Request\UpdateOrderRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UpdateOrderRequestValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /** @return iterable<string, array{array<string,mixed>, list<string>}> */
    public static function invalidPayloads(): iterable
    {
        yield 'missing orderId'  => [['status' => 'shipped', 'notes' => 'ok'], ['orderId']];
        yield 'invalid status'   => [['orderId' => 1, 'status' => 'space', 'notes' => 'ok'], ['status']];
        yield 'notes too long'   => [['orderId' => 1, 'status' => 'shipped', 'notes' => str_repeat('a', 5000)], ['notes']];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsAreRejected(array $payload, array $expectedFields): void
    {
        $dto = new UpdateOrderRequest(
            orderId: $payload['orderId'] ?? 0,
            status:  $payload['status']  ?? '',
            notes:   $payload['notes']   ?? '',
        );

        $violations = $this->validator->validate($dto);

        $this->assertGreaterThan(0, count($violations));

        $fields = [];
        foreach ($violations as $v) {
            $fields[] = $v->getPropertyPath();
        }
        foreach ($expectedFields as $field) {
            $this->assertContains($field, $fields, "Expected violation on {$field}");
        }
    }
}
