<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Http\Request\TransferFundsRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TransferFundsRequestValidationTest extends TestCase
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
        yield 'missing source'   => [['destination' => 'b', 'amount' => 100], ['source']];
        yield 'negative amount'  => [['source' => 'a', 'destination' => 'b', 'amount' => -1], ['amount']];
        yield 'same accounts'    => [['source' => 'a', 'destination' => 'a', 'amount' => 50], ['destination']];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsAreRejected(array $payload, array $expectedFields): void
    {
        $dto = new TransferFundsRequest(
            source:      $payload['source']      ?? '',
            destination: $payload['destination'] ?? '',
            amount:      $payload['amount']      ?? 0,
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
