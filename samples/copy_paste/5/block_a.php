<?php
declare(strict_types=1);

namespace Acme\Crm\Import;

final class ContactImporter
{
    public function __construct(private readonly ContactRepository $contacts)
    {
    }

    public function importRow(array $row): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        $rawPhone = (string) ($row['phone'] ?? '');

        // ---- BEGIN copy-pasted phone normalization ----
        $phone = trim($rawPhone);
        $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone) ?? '';
        $phone = str_replace(['ext.', 'ext', 'x'], '', $phone);
        if (strncmp($phone, '00', 2) === 0) {
            $phone = '+' . substr($phone, 2);
        }
        if ($phone !== '' && $phone[0] !== '+') {
            $phone = '+1' . ltrim($phone, '0');
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw new \InvalidArgumentException("Phone {$rawPhone} is not a valid international number");
        }
        $normalizedPhone = $phone;
        // ---- END copy-pasted phone normalization ----

        $this->contacts->upsert(new Contact($name, $normalizedPhone));
    }
}
