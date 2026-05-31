<?php
declare(strict_types=1);

namespace Acme\Http\Validation;

use Acme\Http\Request;
use Acme\Http\Validation\Exceptions\ValidationException;
use Acme\Payments\CreatePaymentDto;

final class CreatePaymentValidator
{
    public function validate(Request $req): CreatePaymentDto
    {
        $errors = [];

        $orderId = $req->input('order_id', null);
        if ($orderId === null || $orderId === '') {
            $errors['order_id'] = 'required';
        } elseif (!is_numeric($orderId)) {
            $errors['order_id'] = 'not numeric';
        } else {
            $orderId = (int)$orderId;
            if ($orderId <= 0) {
                $errors['order_id'] = 'must be positive';
            }
        }

        $method = strtolower(trim((string)$req->input('method', '')));
        $allowed = ['card', 'paypal', 'ach', 'wire'];
        if ($method === '') {
            $errors['method'] = 'required';
        } elseif (!in_array($method, $allowed, true)) {
            $errors['method'] = 'unsupported';
        }

        $amount = $req->input('amount', null);
        if ($amount === null) {
            $errors['amount'] = 'required';
        } elseif (!is_numeric($amount)) {
            $errors['amount'] = 'not numeric';
        } else {
            $amount = (float)$amount;
            if ($amount <= 0 || $amount > 250_000) {
                $errors['amount'] = 'out of range';
            }
        }

        $token = trim((string)$req->input('token', ''));
        if ($token !== '' && strlen($token) > 128) {
            $errors['token'] = 'too long';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return new CreatePaymentDto((int)$orderId, $method, (float)$amount, $token !== '' ? $token : null);
    }
}
