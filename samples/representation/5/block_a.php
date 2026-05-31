<?php
declare(strict_types=1);

namespace Checkout\Form;

final class ShippingAddressForm
{
    public string $recipientName = '';
    public string $line1 = '';
    public string $line2 = '';
    public string $city = '';
    public string $state = '';
    public string $postalCode = '';
    public string $countryCode = '';
    public ?string $phone = null;

    public function loadFromPost(array $post): void
    {
        $errors = [];
        $this->recipientName = trim((string)($post['name'] ?? ''));
        $this->line1 = trim((string)($post['address1'] ?? ''));
        $this->line2 = trim((string)($post['address2'] ?? ''));
        $this->city = trim((string)($post['city'] ?? ''));
        $this->state = trim((string)($post['state'] ?? ''));
        $this->postalCode = strtoupper(trim((string)($post['zip'] ?? '')));
        $this->countryCode = strtoupper(trim((string)($post['country'] ?? '')));
        $this->phone = !empty($post['phone']) ? preg_replace('/[^0-9+]/', '', (string)$post['phone']) : null;

        if ($this->recipientName === '') $errors[] = 'Name required';
        if ($this->line1 === '') $errors[] = 'Street required';
        if ($this->city === '') $errors[] = 'City required';
        if ($this->postalCode === '') $errors[] = 'ZIP required';
        if (strlen($this->countryCode) !== 2) $errors[] = 'Country must be ISO-2';
        if ($errors) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }
}

final class CheckoutController
{
    public function submit(array $post): ShippingAddressForm
    {
        $form = new ShippingAddressForm();
        $form->loadFromPost($post);
        return $form;
    }
}
