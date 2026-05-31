<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Http\Request\CreateUserRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateUserRequestValidationTest extends TestCase
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
        yield 'missing email'    => [['name' => 'A', 'role' => 'admin'], ['email']];
        yield 'short name'       => [['email' => 'a@b.test', 'name' => 'A', 'role' => 'admin'], ['name']];
        yield 'invalid role'     => [['email' => 'a@b.test', 'name' => 'Alice', 'role' => 'wizard'], ['role']];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsAreRejected(array $payload, array $expectedFields): void
    {
        $dto = new CreateUserRequest(
            email: $payload['email'] ?? '',
            name:  $payload['name']  ?? '',
            role:  $payload['role']  ?? '',
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
