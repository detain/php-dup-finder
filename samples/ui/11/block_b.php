<?php

declare(strict_types=1);

namespace App\View\Field;

use App\Entity\FieldConfig;
use App\Service\Validation\Validator;
use Psr\Log\LoggerInterface;

final class ProfileFieldRenderer
{
    public function __construct(
        private readonly Validator $validator,
        private readonly LoggerInterface $logger,
    ) {}

    public function renderNameField(string $value = '', array $errors = []): string
    {
        $fieldId = 'profile_name';
        $fieldName = 'display_name';
        $label = 'Display Name';
        $placeholder = 'How should we call you?';
        $required = true;
        $maxLength = 100;

        $errorClass = !empty($errors) ? ' has-error' : '';
        $errorMessage = !empty($errors) ? '<p class="field-error-msg">' . htmlspecialchars($errors[0]) . '</p>' : '';

        $html = '<div class="field-wrapper' . $errorClass . '" id="wrapper-' . $fieldId . '">';
        $html .= '<label for="' . $fieldId . '" class="field-label">' . htmlspecialchars($label);
        if ($required) {
            $html .= '<abbr title="required" class="required-mark">*</abbr>';
        }
        $html .= '</label>';
        $html .= '<input type="text" ';
        $html .= 'id="' . $fieldId . '" ';
        $html .= 'name="' . $fieldName . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        $html .= 'maxlength="' . $maxLength . '" ';
        if ($required) {
            $html .= 'aria-required="true" ';
        }
        $html .= 'autocomplete="name" ';
        $html .= 'class="text-input" ';
        $html .= '/>';
        $html .= $errorMessage;
        $html .= '</div>';

        return $html;
    }

    public function renderEmailField(string $value = '', array $errors = []): string
    {
        $fieldId = 'profile_email';
        $fieldName = 'email_address';
        $label = 'Email Address';
        $placeholder = 'your.email@example.com';
        $required = true;
        $maxLength = 255;

        $errorClass = !empty($errors) ? ' has-error' : '';
        $errorMessage = !empty($errors) ? '<p class="field-error-msg">' . htmlspecialchars($errors[0]) . '</p>' : '';

        $html = '<div class="field-wrapper' . $errorClass . '" id="wrapper-' . $fieldId . '">';
        $html .= '<label for="' . $fieldId . '" class="field-label">' . htmlspecialchars($label);
        if ($required) {
            $html .= '<abbr title="required" class="required-mark">*</abbr>';
        }
        $html .= '</label>';
        $html .= '<input type="email" ';
        $html .= 'id="' . $fieldId . '" ';
        $html .= 'name="' . $fieldName . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        $html .= 'maxlength="' . $maxLength . '" ';
        if ($required) {
            $html .= 'aria-required="true" ';
        }
        $html .= 'autocomplete="email" ';
        $html .= 'class="text-input" ';
        $html .= '/>';
        $html .= $errorMessage;
        $html .= '</div>';

        return $html;
    }

    public function renderPhoneField(string $value = '', array $errors = []): string
    {
        $fieldId = 'profile_phone';
        $fieldName = 'phone_number';
        $label = 'Phone Number';
        $placeholder = '+1-555-123-4567';
        $required = false;
        $maxLength = 20;

        $errorClass = !empty($errors) ? ' has-error' : '';
        $errorMessage = !empty($errors) ? '<p class="field-error-msg">' . htmlspecialchars($errors[0]) . '</p>' : '';

        $html = '<div class="field-wrapper' . $errorClass . '" id="wrapper-' . $fieldId . '">';
        $html .= '<label for="' . $fieldId . '" class="field-label">' . htmlspecialchars($label);
        if ($required) {
            $html .= '<abbr title="required" class="required-mark">*</abbr>';
        }
        $html .= '</label>';
        $html .= '<input type="tel" ';
        $html .= 'id="' . $fieldId . '" ';
        $html .= 'name="' . $fieldName . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        $html .= 'maxlength="' . $maxLength . '" ';
        if ($required) {
            $html .= 'aria-required="true" ';
        }
        $html .= 'autocomplete="tel" ';
        $html .= 'class="text-input" ';
        $html .= '/>';
        $html .= $errorMessage;
        $html .= '</div>';

        return $html;
    }

    public function renderFullProfileForm(array $profileData = []): string
    {
        $html = '<form id="profile-edit-form" class="profile-form" action="/profile/update" method="post">';
        $html .= '<fieldset class="field-group">';
        $html .= '<legend>Basic Information</legend>';
        $html .= $this->renderNameField($profileData['display_name'] ?? '', $profileData['errors']['name'] ?? []);
        $html .= $this->renderEmailField($profileData['email'] ?? '', $profileData['errors']['email'] ?? []);
        $html .= $this->renderPhoneField($profileData['phone'] ?? '', $profileData['errors']['phone'] ?? []);
        $html .= '</fieldset>';
        $html .= '<div class="form-actions">';
        $html .= '<button type="submit" class="save-button">Save Changes</button>';
        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }
}
