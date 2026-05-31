<?php
declare(strict_types=1);

namespace App\Ecommerce\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class ProductCodeValidator extends ConstraintValidator
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 30;
    public const PATTERN = '/^[A-Z]{2,4}[0-9]{4,8}[A-Z0-9]{0,2}$/';

    public function validate($value, Constraint $constraint): void
    {
        if (empty($value)) {
            $this->context->buildViolation('Product code is required')
                ->addViolation();
            return;
        }

        if (!is_string($value)) {
            $this->context->buildViolation('Product code must be a string')
                ->addViolation();
            return;
        }

        if (strlen($value) < self::MIN_LENGTH) {
            $this->context->buildViolation(
                'Product code must be at least {{ limit }} characters'
            )->setParameter('{{ limit }}', (string) self::MIN_LENGTH)
                ->addViolation();
        }

        if (strlen($value) > self::MAX_LENGTH) {
            $this->context->buildViolation(
                'Product code cannot exceed {{ limit }} characters'
            )->setParameter('{{ limit }}', (string) self::MAX_LENGTH)
                ->addViolation();
        }

        if (!preg_match(self::PATTERN, $value)) {
            $this->context->buildViolation(
                'Product code must follow the format: 2-4 letter prefix, 4-8 digit number, optional suffix'
            )->addViolation();
        }

        if ($this->containsInvalidCharacters($value)) {
            $this->context->buildViolation(
                'Product code contains invalid characters'
            )->addViolation();
        }
    }

    private function containsInvalidCharacters(string $value): bool
    {
        return (bool) preg_match('/[^A-Z0-9]/', $value);
    }
}

class ProductCodeConstraint extends Constraint
{
    public string $message = 'Invalid product code format';
    public int $minLength = ProductCodeValidator::MIN_LENGTH;
    public int $maxLength = ProductCodeValidator::MAX_LENGTH;
}
