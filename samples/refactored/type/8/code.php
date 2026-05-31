<?php
declare(strict_types=1);

namespace Acme\Http\Validation;

use Acme\Http\Request;
use Acme\Http\Validation\Exceptions\ValidationException;

final class Rule
{
    /** @param callable(mixed):(string|null) $check */
    public function __construct(
        public readonly string $field,
        public readonly bool $required,
        public readonly mixed $cast,
        public readonly mixed $check
    ) {
    }
}

abstract class RuleBasedValidator
{
    /** @return Rule[] */
    abstract protected function rules(): array;
    /** @param array<string,mixed> $clean */
    abstract protected function build(array $clean): object;

    public function validate(Request $req): object
    {
        $errors = [];
        $clean  = [];
        foreach ($this->rules() as $rule) {
            $raw = $req->input($rule->field, null);
            if ($rule->required && ($raw === null || $raw === '')) {
                $errors[$rule->field] = 'required';
                continue;
            }
            if ($raw === null || $raw === '') {
                $clean[$rule->field] = null;
                continue;
            }
            $value = is_callable($rule->cast) ? ($rule->cast)($raw) : $raw;
            $err   = is_callable($rule->check) ? ($rule->check)($value) : null;
            if ($err !== null) {
                $errors[$rule->field] = $err;
            } else {
                $clean[$rule->field] = $value;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->build($clean);
    }
}
