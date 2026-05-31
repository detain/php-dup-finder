<?php

declare(strict_types=1);

namespace App\View\Modal;

use Psr\Log\LoggerInterface;

final class FormModalRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderForm(string $id, string $title, string $action, array $fields, FormModalOptions $options): string
    {
        $html = '<div id="' . htmlspecialchars($id) . '" class="modal-overlay form-modal" role="dialog" aria-modal="true" aria-labelledby="form-modal-title-' . $id . '">';
        $html .= '<div class="modal-container modal-size-' . ($options->size ?? 'large') . '">';
        $html .= '<div class="modal-header">';
        $html .= '<h2 id="form-modal-title-' . $id . '" class="modal-title">' . htmlspecialchars($title) . '</h2>';
        $html .= '<button type="button" class="modal-close" aria-label="Close" data-modal-close="' . $id . '">×</button>';
        $html .= '</div>';
        $html .= '<form id="' . $id . '-form" class="modal-form" action="' . htmlspecialchars($action) . '" method="POST">';
        $html .= '<div class="modal-body">';

        foreach ($fields as $field) {
            $html .= $this->renderField($field);
        }

        if ($options->showCsrfToken) {
            $html .= '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($options->csrfToken ?? '') . '">';
        }

        $html .= '</div>';
        $html .= '<div class="modal-footer">';
        $html .= '<button type="button" class="btn btn-secondary" data-modal-cancel="' . $id . '">';
        $html .= htmlspecialchars($options->cancelLabel ?? 'Cancel');
        $html .= '</button>';
        $html .= '<button type="submit" class="btn btn-primary"';
        if ($options->submitId) {
            $html .= ' id="' . htmlspecialchars($options->submitId) . '"';
        }
        $html .= '>';
        $html .= htmlspecialchars($options->submitLabel ?? 'Submit');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';
        $html .= '</div>';

        $this->logger->debug('Rendered form modal', ['id' => $id]);

        return $html;
    }

    private function renderField(array $field): string
    {
        $fieldId = $field['id'] ?? 'field_' . uniqid();
        $fieldName = $field['name'] ?? '';
        $fieldType = $field['type'] ?? 'text';
        $label = $field['label'] ?? '';
        $value = $field['value'] ?? '';
        $required = $field['required'] ?? false;
        $error = $field['error'] ?? '';
        $placeholder = $field['placeholder'] ?? '';

        $wrapperClass = 'form-field' . ($error ? ' has-error' : '');
        $inputClass = 'form-input' . ($error ? ' is-invalid' : '');

        $html = '<div class="' . $wrapperClass . '">';
        $html .= '<label for="' . htmlspecialchars($fieldId) . '" class="field-label">';
        $html .= htmlspecialchars($label);
        if ($required) {
            $html .= ' <span class="required">*</span>';
        }
        $html .= '</label>';

        if ($fieldType === 'textarea') {
            $html .= '<textarea id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($fieldName) . '"';
            $html .= ' class="' . $inputClass . '" rows="4"';
            if ($required) {
                $html .= ' required';
            }
            $html .= ' placeholder="' . htmlspecialchars($placeholder) . '">' . htmlspecialchars($value) . '</textarea>';
        } else {
            $html .= '<input type="' . htmlspecialchars($fieldType) . '"';
            $html .= ' id="' . htmlspecialchars($fieldId) . '"';
            $html .= ' name="' . htmlspecialchars($fieldName) . '"';
            $html .= ' value="' . htmlspecialchars($value) . '"';
            $html .= ' class="' . $inputClass . '"';
            if ($required) {
                $html .= ' required';
            }
            $html .= ' placeholder="' . htmlspecialchars($placeholder) . '">';
        }

        if ($error) {
            $html .= '<span class="field-error">' . htmlspecialchars($error) . '</span>';
        }

        $html .= '</div>';

        return $html;
    }

    public function renderQuickEditForm(string $id, string $title, string $action, array $fieldData): string
    {
        $fields = [];
        foreach ($fieldData as $name => $value) {
            $fields[] = [
                'name' => $name,
                'label' => ucwords(str_replace('_', ' ', $name)),
                'value' => $value,
                'type' => 'text',
            ];
        }

        $options = new FormModalOptions(
            size: 'medium',
            submitLabel: 'Save',
            showCsrfToken: true,
        );

        return $this->renderForm($id, $title, $action, $fields, $options);
    }
}
