<?php
declare(strict_types=1);

namespace Acme\Http\Validation;

use Acme\Http\Request;
use Acme\Http\Validation\Exceptions\ValidationException;
use Acme\Users\CreateUserDto;

final class CreateUserValidator
{
    public function validate(Request $req): CreateUserDto
    {
        $errors = [];

        $email = trim((string)$req->input('email', ''));
        if ($email === '') {
            $errors['email'] = 'required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'invalid';
        } elseif (strlen($email) > 254) {
            $errors['email'] = 'too long';
        }

        $name = trim((string)$req->input('name', ''));
        if ($name === '') {
            $errors['name'] = 'required';
        } elseif (strlen($name) > 100) {
            $errors['name'] = 'too long';
        }

        $age = $req->input('age', null);
        if ($age !== null) {
            if (!is_numeric($age)) {
                $errors['age'] = 'not numeric';
            } else {
                $age = (int)$age;
                if ($age < 13 || $age > 120) {
                    $errors['age'] = 'out of range';
                }
            }
        }

        $country = strtoupper(trim((string)$req->input('country', '')));
        if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
            $errors['country'] = 'must be ISO-3166 alpha-2';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return new CreateUserDto($email, $name, is_int($age) ? $age : null, $country !== '' ? $country : null);
    }
}
