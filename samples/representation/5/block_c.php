<?php
declare(strict_types=1);

namespace Persistence\Addresses;

final class AddressRow
{
    public int $id = 0;
    public int $customer_id = 0;
    public string $recipient = '';
    public string $street_1 = '';
    public string $street_2 = '';
    public string $city = '';
    public string $region = '';
    public string $postcode = '';
    public string $country_name = '';
    public ?string $contact_phone = null;
    public \DateTimeImmutable $created_at;

    public function __construct() { $this->created_at = new \DateTimeImmutable(); }

    public function fillFromArray(array $row): void
    {
        $errors = [];
        $this->id = (int)($row['id'] ?? 0);
        $this->customer_id = (int)($row['customer_id'] ?? 0);
        $this->recipient = trim((string)$row['name']);
        $this->street_1 = trim((string)$row['street']);
        $this->street_2 = trim((string)($row['street2'] ?? ''));
        $this->city = trim((string)$row['city']);
        $this->region = trim((string)($row['region'] ?? ''));
        $this->postcode = strtoupper(trim((string)$row['zip']));

        $names = ['US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom'];
        $iso = strtoupper((string)$row['country']);
        $this->country_name = $names[$iso] ?? $iso;
        $this->contact_phone = !empty($row['phone'])
            ? preg_replace('/[^0-9+]/', '', (string)$row['phone'])
            : null;

        if ($this->recipient === '') $errors[] = 'recipient required';
        if ($this->street_1 === '') $errors[] = 'street required';
        if ($this->city === '') $errors[] = 'city required';
        if ($errors) {
            throw new \RuntimeException(implode('; ', $errors));
        }
    }
}

final class AddressRepository
{
    public function __construct(private \PDO $pdo) {}

    public function save(AddressRow $row): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO addresses (customer_id, recipient, street_1, city, postcode, country_name) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$row->customer_id, $row->recipient, $row->street_1, $row->city, $row->postcode, $row->country_name]);
        return (int)$this->pdo->lastInsertId();
    }
}
