<?php
declare(strict_types=1);

namespace Validation\Sanitization;

use Psr\Log\LoggerInterface;

final class OrderInputSanitizer
{
    private const MAX_ORDER_NOTES_LENGTH = 1000;
    private const MAX_SHIPPING_ADDRESS_LENGTH = 500;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function sanitizeForCreate(array $input): SanitizedInput
    {
        $sanitized = [];

        $sanitized['customer_email'] = $this->sanitizeEmail($input['customer_email'] ?? '');
        $sanitized['items'] = $this->sanitizeItems($input['items'] ?? []);
        $sanitized['shipping_address'] = $this->sanitizeAddress($input['shipping_address'] ?? []);
        $sanitized['billing_address'] = $this->sanitizeAddress($input['billing_address'] ?? []);
        $sanitized['shipping_method'] = $this->sanitizeShippingMethod($input['shipping_method'] ?? 'standard');
        $sanitized['payment_method'] = $this->sanitizePaymentMethod($input['payment_method'] ?? 'credit_card');
        $sanitized['notes'] = $this->sanitizeNotes($input['notes'] ?? '');
        $sanitized['coupon_code'] = $this->sanitizeCouponCode($input['coupon_code'] ?? null);
        $sanitized['wants_invoice'] = $this->sanitizeBoolean($input['wants_invoice'] ?? false);

        $this->logger->debug('Order input sanitized for create', [
            'item_count' => count($sanitized['items']),
        ]);

        return new SanitizedInput(
            data: $sanitized,
            violations: $this->validate($sanitized),
            sanitizedAt: new \DateTimeImmutable(),
        );
    }

    public function sanitizeForUpdate(array $input, Order $existingOrder): SanitizedInput
    {
        $sanitized = [];

        if (isset($input['status'])) {
            $sanitized['status'] = $this->sanitizeStatus($input['status']);
        }

        if (isset($input['shipping_address'])) {
            $sanitized['shipping_address'] = $this->sanitizeAddress($input['shipping_address']);
        }

        if (isset($input['notes'])) {
            $sanitized['notes'] = $this->sanitizeNotes($input['notes']);
        }

        if (isset($input['shipping_method'])) {
            $sanitized['shipping_method'] = $this->sanitizeShippingMethod($input['shipping_method']);
        }

        $this->logger->debug('Order input sanitized for update', [
            'updated_fields' => array_keys($sanitized),
        ]);

        return new SanitizedInput(
            data: $sanitized,
            violations: $this->validatePartial($sanitized),
            sanitizedAt: new \DateTimeImmutable(),
        );
    }

    private function sanitizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }

    private function sanitizeItems(array $items): array
    {
        $sanitizedItems = [];

        foreach ($items as $item) {
            $sanitizedItems[] = [
                'sku' => strtoupper(preg_replace('/[^A-Z0-9]/', '', $item['sku'] ?? '')),
                'quantity' => max(1, (int)($item['quantity'] ?? 1)),
                'unit_price' => max(0, (float)($item['unit_price'] ?? 0)),
            ];
        }

        return $sanitizedItems;
    }

    private function sanitizeAddress(array $address): array
    {
        return [
            'recipient_name' => $this->truncate($this->removeControlChars($address['recipient_name'] ?? ''), 100),
            'street' => $this->truncate($this->removeControlChars($address['street'] ?? ''), self::MAX_SHIPPING_ADDRESS_LENGTH),
            'city' => $this->truncate($this->removeControlChars($address['city'] ?? ''), 100),
            'state' => strtoupper(preg_replace('/[^A-Z]/', '', $address['state'] ?? '')),
            'postal_code' => preg_replace('/\D/', '', $address['postal_code'] ?? ''),
            'country' => strtoupper(substr($address['country'] ?? 'US', 0, 2)),
            'phone' => $this->sanitizePhone($address['phone'] ?? ''),
        ];
    }

    private function sanitizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 10) {
            return sprintf('+1-%s-%s', substr($digits, 0, 3), substr($digits, 3, 3));
        }

        return $phone;
    }

    private function sanitizeShippingMethod(string $method): string
    {
        $validMethods = ['standard', 'expedited', 'overnight', 'freight'];

        if (in_array($method, $validMethods)) {
            return $method;
        }

        return 'standard';
    }

    private function sanitizePaymentMethod(string $method): string
    {
        $validMethods = ['credit_card', 'debit_card', 'paypal', 'bank_transfer', 'cod'];

        if (in_array($method, $validMethods)) {
            return $method;
        }

        return 'credit_card';
    }

    private function sanitizeNotes(string $notes): string
    {
        $notes = trim($notes);
        $notes = $this->removeControlChars($notes);

        return $this->truncate($notes, self::MAX_ORDER_NOTES_LENGTH);
    }

    private function sanitizeCouponCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $code = strtoupper(trim($code));

        return preg_replace('/[^A-Z0-9]/', '', $code);
    }

    private function sanitizeBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function sanitizeStatus(string $status): string
    {
        $validStatuses = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];

        if (in_array($status, $validStatuses)) {
            return $status;
        }

        return 'pending';
    }

    private function validate(array $data): array
    {
        $violations = [];

        if (!filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $violations[] = 'customer_email_invalid';
        }

        if (empty($data['items'])) {
            $violations[] = 'items_required';
        }

        if (empty($data['shipping_address']['street'])) {
            $violations[] = 'shipping_address_required';
        }

        return $violations;
    }

    private function validatePartial(array $data): array
    {
        return [];
    }

    private function removeControlChars(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    private function truncate(string $value, int $maxLength): string
    {
        return substr($value, 0, $maxLength);
    }
}
