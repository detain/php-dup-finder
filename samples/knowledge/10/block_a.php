<?php

declare(strict_types=1);

namespace App\Encryption;

use App\Crypto\AesGcmCipher;
use App\Exceptions\EncryptionException;

final class CustomerFieldEncryptor
{
    public function __construct(private AesGcmCipher $cipher) {}

    public function encryptForStorage(array $row): array
    {
        $sensitiveFields = ['ssn', 'date_of_birth', 'phone', 'email', 'full_name', 'address_line'];

        foreach ($sensitiveFields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if ($value === null || $value === '') {
                continue;
            }
            try {
                $row[$field] = $this->cipher->encrypt((string) $value);
                $row[$field . '_search_hash'] = hash('sha256', strtolower(trim((string) $value)));
            } catch (\Throwable $e) {
                throw new EncryptionException(
                    sprintf('Failed to encrypt %s: %s', $field, $e->getMessage()),
                    0,
                    $e
                );
            }
        }

        return $row;
    }

    public function decryptForUse(array $row): array
    {
        $sensitiveFields = ['ssn', 'date_of_birth', 'phone', 'email', 'full_name', 'address_line'];
        foreach ($sensitiveFields as $field) {
            if (!isset($row[$field]) || $row[$field] === '') {
                continue;
            }
            $row[$field] = $this->cipher->decrypt((string) $row[$field]);
            unset($row[$field . '_search_hash']);
        }
        return $row;
    }
}
