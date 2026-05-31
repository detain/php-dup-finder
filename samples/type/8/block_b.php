<?php
declare(strict_types=1);

namespace Acme\Http\Validation;

use Acme\Http\Request;
use Acme\Http\Validation\Exceptions\ValidationException;
use Acme\Orders\CreateOrderDto;

final class CreateOrderValidator
{
    public function validate(Request $req): CreateOrderDto
    {
        $errors = [];

        $customerId = $req->input('customer_id', null);
        if ($customerId === null || $customerId === '') {
            $errors['customer_id'] = 'required';
        } elseif (!is_numeric($customerId)) {
            $errors['customer_id'] = 'not numeric';
        } else {
            $customerId = (int)$customerId;
            if ($customerId <= 0) {
                $errors['customer_id'] = 'must be positive';
            }
        }

        $currency = strtoupper(trim((string)$req->input('currency', '')));
        if ($currency === '') {
            $errors['currency'] = 'required';
        } elseif (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = 'must be ISO-4217';
        }

        $amount = $req->input('amount', null);
        if ($amount === null) {
            $errors['amount'] = 'required';
        } elseif (!is_numeric($amount)) {
            $errors['amount'] = 'not numeric';
        } else {
            $amount = (float)$amount;
            if ($amount <= 0 || $amount > 1_000_000) {
                $errors['amount'] = 'out of range';
            }
        }

        $note = trim((string)$req->input('note', ''));
        if ($note !== '' && strlen($note) > 500) {
            $errors['note'] = 'too long';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return new CreateOrderDto((int)$customerId, $currency, (float)$amount, $note !== '' ? $note : null);
    }
}
