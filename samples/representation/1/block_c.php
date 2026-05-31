<?php
declare(strict_types=1);

namespace Acme\Crm\Mail\View;

final class CustomerEmailViewModel
{
    public string $customer_id;
    public string $greeting_name;
    public string $surname;
    public string $email_address;
    public ?string $phone_display;
    public string $member_since;
    public string $last_updated;

    public function populate(array $data): void
    {
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email view requires valid email');
        }
        if (empty($data['first_name']) || empty($data['last_name'])) {
            throw new \InvalidArgumentException('Email view requires both name fields');
        }
        $this->customer_id = (string)$data['id'];
        $this->greeting_name = trim((string)$data['first_name']);
        $this->surname = trim((string)$data['last_name']);
        $this->email_address = strtolower(trim((string)$data['email']));
        $this->phone_display = isset($data['phone']) ? trim((string)$data['phone']) : null;
        $this->member_since = (new \DateTimeImmutable((string)$data['created_at']))->format('F j, Y');
        $this->last_updated = (new \DateTimeImmutable((string)$data['updated_at']))->format('F j, Y');
    }

    public function fullName(): string
    {
        return $this->greeting_name . ' ' . $this->surname;
    }
}

final class WelcomeEmailRenderer
{
    public function render(array $customerData): string
    {
        $vm = new CustomerEmailViewModel();
        $vm->populate($customerData);
        return sprintf("Hello %s,\nThanks for joining on %s.", $vm->fullName(), $vm->member_since);
    }
}
