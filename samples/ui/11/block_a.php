<?php

declare(strict_types=1);

namespace App\View\Field;

use App\Entity\FieldConfig;
use App\Service\Validation\Validator;
use Psr\Log\LoggerInterface;

final class RegistrationFieldRenderer
{
    public function __construct(
        private readonly Validator $validator,
        private readonly LoggerInterface $logger,
    ) {}

    public function renderNameField(string $value = '', array $errors = []): string
    {
        $fieldId = 'field_name';
        $fieldName = 'name';
        $label = 'Full Name';
        $placeholder = 'Enter your full name';
        $required = true;
        $maxLength = 100;

        $errorClass = !empty($errors) ? ' field-error' : '';
        $errorMessage = !empty($errors) ? '<span class="error-text">' . htmlspecialchars($errors[0]) . '</span>' : '';

        $html = '<div class="form-field' . $errorClass . '" data-field="' . $fieldId . '">';
        $html .= '<label for="' . $fieldId . '" class="field-label">';
        if ($required) {
            $html .= '<span class="required-indicator">*</span>';
        }
        $html .= htmlspecialchars($label) . '</label>';
        $html .= '<input type="text" ';
        $html .= 'id="' . $fieldId . '" ';
        $html .= 'name="' . $fieldName . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        $html .= 'maxlength="' . $maxLength . '" ';
        if ($required) {
            $html .= 'required ';
        }
        $html .= 'autocomplete="name" ';
        $html .= 'class="field-input" ';
        $html .= '/>';
        $html .= $errorMessage;
        $html .= '</div>';

        return $html;
    }

    public function renderEmailField(string $value = '', array $errors = []): string
    {
        $fieldId = 'field_email';
        $fieldName = 'email';
        $label = 'Email Address';
        $placeholder = 'you@example.com';
        $required = true;
        $maxLength = 255;

        $errorClass = !empty($errors) ? ' field-error' : '';
        $errorMessage = !empty($errors) ? '<span class="error-text">' . htmlspecialchars($errors[0]) . '</span>' : '';

        $html = '<div class="form-field' . $errorClass . '" data-field="' . $fieldId . '">';
        $html .= '<label for="' . $fieldId . '" class="field-label">';
        if ($required) {
            $html .= '<span class="required-indicator">*</span>';
        }
        $html .= htmlspecialchars($label) . '</label>';
        $html .= '<input type="email" ';
        $html .= 'id="' . $fieldId . '" ';
        $html .= 'name="' . $fieldName . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        $html .= 'maxlength="' . $maxLength . '" ';
        if ($required) {
            $html .= 'required ';
        }
        $html .= 'autocomplete="email" ';
        $html .= 'class="field-input" ';
        $html .= '/>';
        $html .= $errorMessage;
        $html .= '</div>';

        return $html;
    }

    public function renderPhoneField(string $value = '', array $errors = []): string
    {
        $fieldId = 'field_phone';
        $fieldName = 'phone';
        $label = 'Phone Number';
        $placeholder = '+1 (555) 123-4567';
        $required = false;
        $maxLength = 20;

        $errorClass = !empty($errors) ? ' field-error' : '';
        $errorMessage = !empty($errors) ? '<span class="error-text">' . htmlspecialchars($errors[0]) . '</span>' : '';

        $html = '<div class="form-field' . $errorClass . '" data-field="' . $fieldId . '">';
        $html .= '<label for="' . $fieldId . '" class="field-label">';
        if ($required) {
            $html .= '<span class="required-indicator">*</span>';
        }
        $html .= htmlspecialchars($label) . '</label>';
        $html .= '<input type="tel" ';
        $html .= 'id="' . $fieldId . '" ';
        $html .= 'name="' . $fieldName . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        $html .= 'maxlength="' . $maxLength . '" ';
        if ($required) {
            $html .= 'required ';
        }
        $html .= 'autocomplete="tel" ';
        $html .= 'class="field-input" ';
        $html .= '/>';
        $html .= $errorMessage;
        $html .= '</div>';

        return $html;
    }

    public function renderFullRegistrationForm(array $data = []): string
    {
        $html = '<form class="registration-form" method="post" novalidate="true">';
        $html .= $this->renderNameField($data['name'] ?? '', $data['errors']['name'] ?? []);
        $html .= $this->renderEmailField($data['email'] ?? '', $data['errors']['email'] ?? []);
        $html .= $this->renderPhoneField($data['phone'] ?? '', $data['errors']['phone'] ?? []);
        $html .= '<button type="submit" class="btn-submit">Register</button>';
        $html .= '</form>';

        return $html;
    }
}
