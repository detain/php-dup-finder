<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

final class PiiFieldRegistry
{
    /** @var list<string> */
    public const FIELDS = ['ssn', 'date_of_birth', 'phone', 'email', 'full_name', 'address_line'];

    public static function isPii(string $field): bool
    {
        return in_array($field, self::FIELDS, true);
    }

    public static function pickFrom(array $row): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $row)) {
                $out[$f] = $row[$f];
            }
        }
        return $out;
    }
}

// CustomerFieldEncryptor:
// foreach (PiiFieldRegistry::FIELDS as $field) {
//     if (!array_key_exists($field, $row) || $row[$field] === null) continue;
//     $row[$field] = $this->cipher->encrypt((string) $row[$field]);
// }

// SubjectAccessExporter:
// $payload['personal_information'] = PiiFieldRegistry::pickFrom([
//     'ssn' => $customer->ssn, 'date_of_birth' => $customer->dateOfBirth, 'phone' => $customer->phone,
//     'email' => $customer->email, 'full_name' => $customer->fullName, 'address_line' => $customer->addressLine,
// ]);

// RedactingLogger:
// foreach (PiiFieldRegistry::FIELDS as $field) {
//     if (isset($context[$field])) { $context[$field] = '[REDACTED]'; }
// }
