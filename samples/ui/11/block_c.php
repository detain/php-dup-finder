<?php

declare(strict_types=1);

namespace App\View\Field;

use App\Entity\FieldConfig;
use App\Service\Validation\Validator;
use Psr\Log\LoggerInterface;

final class CheckoutFieldRenderer
{
    public function __construct(
        private readonly Validator $validator,
        private readonly LoggerInterface $logger,
    ) {}

    public function renderNameField(string $value = '', array $errors = []): string
    {
        $fieldId = 'checkout-name';
        $fieldName = 'customer_name';
        $label = 'Name on Card';
        $placeholder = 'John Doe';
        $required = true;
        $maxLength = 100;

        $hasError = !empty($errors);
        $wrapperClass = $hasError ? ' input-group has-validation' : ' input-group';
        $inputClass = $hasError ? ' input-control is-invalid' : ' input-control';

        $html = '<div class="' . trim($wrapperClass) . '">';
        $html .= '<label for="' . $fieldId . '" class="input-label">';
        $html .= htmlspecialchars($label);
        if ($required) {
            $html .= ' <span class="required">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="text" ';
        $html .= 'id="' . $fieldId . '" ';
        $html .= 'name="' . $fieldName . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        $html .= 'maxlength="' . $maxLength . '" ';
        if ($required) {
            $html .= 'data-required="1" ';
        }
        $html .= 'autocomplete="cc-name" ';
        $html .= 'class="' . trim($inputClass) . '" ';
        $html .= '/>';
        if ($hasError) {
            $html .= '<div class="invalid-feedback">' . htmlspecialchars($errors[0]) . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    public function renderEmailField(string $value = '', array $errors = []): string
    {
        $fieldId = 'checkout-email';
        $fieldName = 'billing_email';
        $label = 'Billing Email';
        $placeholder = 'billing@example.com';
        $required = true;
        $maxLength = 255;

        $hasError = !empty($errors);
        $wrapperClass = $hasError ? ' input-group has-validation' : ' input-group';
        $inputClass = $hasError ? ' input-control is-invalid' : ' input-control';

        $html = '<div class="' . trim($wrapperClass) . '">';
        $html .= '<label for="' . $fieldId . '" class="input-label">';
        $html .= htmlspecialchars($label);
        if ($required) {
            $html .= ' <span class="required">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="email" ';
        $html .= 'id="' . $fieldId . '" ';
        $html .= 'name="' . $fieldName . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        $html .= 'maxlength="' . $maxLength . '" ';
        if ($required) {
            $html .= 'data-required="1" ';
        }
        $html .= 'autocomplete="email" ';
        $html .= 'class="' . trim($inputClass) . '" ';
        $html .= '/>';
        if ($hasError) {
            $html .= '<div class="invalid-feedback">' . htmlspecialchars($errors[0]) . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    public function renderPhoneField(string $value = '', array $errors = []): string
    {
        $fieldId = 'checkout-phone';
        $fieldName = 'contact_phone';
        $label = 'Contact Phone';
        $placeholder = '(555) 123-4567';
        $required = false;
        $maxLength = 20;

        $hasError = !empty($errors);
        $wrapperClass = $hasError ? ' input-group has-validation' : ' input-group';
        $inputClass = $hasError ? ' input-control is-invalid' : ' input-control';

        $html = '<div class="' . trim($wrapperClass) . '">';
        $html .= '<label for="' . $fieldId . '" class="input-label">';
        $html .= htmlspecialchars($label);
        if ($required) {
            $html .= ' <span class="required">*</span>';
        }
        $html .= '</label>';
        $html .= '<input type="tel" ';
        $html .= 'id="' . $fieldId . '" ';
        $html .= 'name="' . $fieldName . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        $html .= 'maxlength="' . $maxLength . '" ';
        if ($required) {
            $html .= 'data-required="1" ';
        }
        $html .= 'autocomplete="tel" ';
        $html .= 'class="' . trim($inputClass) . '" ';
        $html .= '/>';
        if ($hasError) {
            $html .= '<div class="invalid-feedback">' . htmlspecialchars($errors[0]) . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    public function renderCheckoutForm(array $formData = []): string
    {
        $html = '<div class="checkout-form-container">';
        $html .= '<form method="post" id="payment-form" class="checkout-form" novalidate>';
        $html .= '<section class="form-section">';
        $html .= '<h3 class="section-title">Contact Details</h3>';
        $html .= $this->renderEmailField($formData['email'] ?? '', $formData['errors']['email'] ?? []);
        $html .= $this->renderPhoneField($formData['phone'] ?? '', $formData['errors']['phone'] ?? []);
        $html .= '</section>';
        $html .= '<section class="form-section">';
        $html .= '<h3 class="section-title">Payment Information</h3>';
        $html .= $this->renderNameField($formData['name'] ?? '', $formData['errors']['name'] ?? []);
        $html .= '</section>';
        $html .= '<button type="submit" class="pay-button">Pay Now</button>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }
}
