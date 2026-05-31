<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

abstract class AbstractDtoValidationTest extends TestCase
{
    protected ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * @param object         $dto
     * @param list<string>   $expectedFields
     */
    protected function assertViolatesFields(object $dto, array $expectedFields): void
    {
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
