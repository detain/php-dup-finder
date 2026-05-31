<?php

declare(strict_types=1);

namespace App\Http\Validation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PaymentValidationService
{
    private const MIN_AMOUNT = 0.01;
    private const MAX_AMOUNT = 999999.99;
    private const AMOUNT_DECIMAL_PLACES = 2;
    private const MIN_INSTALLMENTS = 1;
    private const MAX_INSTALLMENTS = 12;
    private const CARD_NUMBER_LENGTH = 16;
    private const CARD_CVV_LENGTH = 3;
    private const CARD_EXPIRY_MONTH_MIN = 1;
    private const CARD_EXPIRY_MONTH_MAX = 12;
    private const CARD_EXPIRY_YEAR_OFFSET = 20;
    private const CVV_PATTERN = '/^[0-9]{3,4}$/';
    private const CARDHOLDER_NAME_MIN = 2;
    private const CARDHOLDER_NAME_MAX = 50;
    private const ALLOWED_CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
    private const ALLOWED_PAYMENT_METHODS = ['credit_card', 'debit_card', 'paypal', 'bank_transfer', 'crypto'];
    private const ALLOWED_COUNTRIES = ['US', 'CA', 'GB', 'DE', 'FR', 'AU', 'JP'];
    private const MIN_TRANSACTION_ID_LENGTH = 10;
    private const MAX_TRANSACTION_ID_LENGTH = 100;
    private const VALIDATION_STRICT = true;
    private const VALIDATION_VERIFY_BIN = false;
    private const VALIDATION_3D_SECURE = true;

    public function validate(Request $request): array
    {
        $rules = $this->getRules();
        $messages = $this->getMessages();

        $validator = validator($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->toArray(),
            ];
        }

        return [
            'valid' => true,
            'data' => $validator->validated(),
        ];
    }

    public function validateCardNumber(string $cardNumber): array
    {
        $errors = [];

        $cardNumber = preg_replace('/\s+/', '', $cardNumber);

        if (!ctype_digit($cardNumber)) {
            $errors['card_number'][] = 'Card number must contain only digits';
        }

        if (strlen($cardNumber) !== self::CARD_NUMBER_LENGTH) {
            $errors['card_number'][] = sprintf(
                'Card number must be exactly %d digits',
                self::CARD_NUMBER_LENGTH
            );
        }

        if (!$this->luhnCheck($cardNumber)) {
            $errors['card_number'][] = 'Invalid card number';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function validateCvv(string $cvv): array
    {
        $errors = [];

        if (!preg_match(self::CVV_PATTERN, $cvv)) {
            $errors['cvv'][] = sprintf(
                'CVV must be %d to %d digits',
                self::CARD_CVV_LENGTH,
                self::CARD_CVV_LENGTH + 1
            );
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function validateExpiryDate(string $expiryMonth, string $expiryYear): array
    {
        $errors = [];

        $month = (int) $expiryMonth;
        $year = (int) $expiryYear;

        if ($month < self::CARD_EXPIRY_MONTH_MIN || $month > self::CARD_EXPIRY_MONTH_MAX) {
            $errors['expiry'][] = sprintf(
                'Expiry month must be between %d and %d',
                self::CARD_EXPIRY_MONTH_MIN,
                self::CARD_EXPIRY_MONTH_MAX
            );
        }

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');

        if ($year < $currentYear || ($year === $currentYear && $month < $currentMonth)) {
            $errors['expiry'][] = 'Card has expired';
        }

        if ($year > $currentYear + self::CARD_EXPIRY_YEAR_OFFSET) {
            $errors['expiry'][] = 'Expiry date is too far in the future';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function validateAmount(?float $amount): array
    {
        $errors = [];

        if ($amount === null) {
            $errors['amount'][] = 'Amount is required';
            return ['valid' => false, 'errors' => $errors];
        }

        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            $errors['amount'][] = sprintf(
                'Amount must be between %.2f and %.2f',
                self::MIN_AMOUNT,
                self::MAX_AMOUNT
            );
        }

        $decimalPlaces = strlen(substr(strrchr((string) $amount, '.'), 1));

        if ($decimalPlaces > self::AMOUNT_DECIMAL_PLACES) {
            $errors['amount'][] = sprintf(
                'Amount cannot have more than %d decimal places',
                self::AMOUNT_DECIMAL_PLACES
            );
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function validateCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::ALLOWED_CURRENCIES, true);
    }

    public function validatePaymentMethod(string $method): bool
    {
        return in_array($method, self::ALLOWED_PAYMENT_METHODS, true);
    }

    public function validateCountry(string $country): bool
    {
        return in_array(strtoupper($country), self::ALLOWED_COUNTRIES, true);
    }

    private function luhnCheck(string $cardNumber): bool
    {
        $sum = 0;
        $length = strlen($cardNumber);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $cardNumber[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    private function getRules(): array
    {
        return [
            'amount' => [
                'required',
                'numeric',
                'min:' . self::MIN_AMOUNT,
                'max:' . self::MAX_AMOUNT,
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::in(self::ALLOWED_CURRENCIES),
            ],
            'payment_method' => [
                'required',
                'string',
                Rule::in(self::ALLOWED_PAYMENT_METHODS),
            ],
            'card_number' => [
                'required_if:payment_method,credit_card,debit_card',
                'string',
                'regex:/^[0-9]{16}$/',
            ],
            'cvv' => [
                'required_if:payment_method,credit_card,debit_card',
                'string',
                'regex:' . self::CVV_PATTERN,
            ],
            'expiry_month' => [
                'required_if:payment_method,credit_card,debit_card',
                'integer',
                'min:1',
                'max:12',
            ],
            'expiry_year' => [
                'required_if:payment_method,credit_card,debit_card',
                'integer',
                'min:' . (int) date('Y'),
            ],
            'cardholder_name' => [
                'required_if:payment_method,credit_card,debit_card',
                'string',
                'min:' . self::CARDHOLDER_NAME_MIN,
                'max:' . self::CARDHOLDER_NAME_MAX,
            ],
            'billing_country' => [
                'required',
                'string',
                'size:2',
                Rule::in(self::ALLOWED_COUNTRIES),
            ],
        ];
    }

    private function getMessages(): array
    {
        return [
            'amount.required' => 'Payment amount is required',
            'amount.min' => sprintf('Amount must be at least %.2f', self::MIN_AMOUNT),
            'currency.in' => 'Invalid currency code',
            'card_number.required_if' => 'Card number is required for card payments',
            'cvv.required_if' => 'CVV is required for card payments',
        ];
    }

    public function getAllowedCurrencies(): array
    {
        return self::ALLOWED_CURRENCIES;
    }

    public function getAllowedPaymentMethods(): array
    {
        return self::ALLOWED_PAYMENT_METHODS;
    }

    public function getAmountRange(): array
    {
        return [
            'min' => self::MIN_AMOUNT,
            'max' => self::MAX_AMOUNT,
            'decimal_places' => self::AMOUNT_DECIMAL_PLACES,
        ];
    }
}
