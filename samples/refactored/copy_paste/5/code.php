<?php
declare(strict_types=1);

namespace Acme\Crm\Phone;

final class PhoneNormalizer
{
    public function normalize(string $raw): string
    {
        $phone = trim($raw);
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
            throw new \InvalidArgumentException("Phone {$raw} is not a valid international number");
        }
        return $phone;
    }
}

final class ContactImporter
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly PhoneNormalizer $normalizer,
    ) {
    }

    public function importRow(array $row): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        $phone = $this->normalizer->normalize((string) ($row['phone'] ?? ''));
        $this->contacts->upsert(new Contact($name, $phone));
    }
}
