<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedModalRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function render(string $type, string $id, string $title, string $content, array $options = []): string
    {
        $size = $options['size'] ?? 'medium';
        $showClose = $options['showClose'] ?? true;

        $html = '<div id="' . htmlspecialchars($id) . '" class="modal-overlay" role="dialog" aria-modal="true">';
        $html .= '<div class="modal-container modal-size-' . $size . '">';
        $html .= '<div class="modal-header">';
        $html .= '<h2 class="modal-title">' . htmlspecialchars($title) . '</h2>';
        if ($showClose) {
            $html .= '<button type="button" class="modal-close" aria-label="Close" data-modal-close="' . $id . '">×</button>';
        }
        $html .= '</div>';
        $html .= '<div class="modal-body">' . $content . '</div>';
        $html .= $this->renderFooter($options);
        $html .= '</div>';
        $html .= '</div>';

        $this->logger->debug('Rendered modal', ['type' => $type, 'id' => $id]);

        return $html;
    }

    public function renderConfirm(string $id, string $title, string $message, array $options = []): string
    {
        $confirmLabel = $options['confirmLabel'] ?? 'Confirm';
        $cancelLabel = $options['cancelLabel'] ?? 'Cancel';
        $dangerous = $options['dangerous'] ?? false;
        $confirmAction = $options['confirmAction'] ?? '';

        $content = '<p class="modal-message">' . htmlspecialchars($message) . '</p>';
        $content .= '<div class="modal-footer-buttons">';
        $content .= '<button type="button" class="btn btn-secondary" data-modal-cancel>' . htmlspecialchars($cancelLabel) . '</button>';
        $content .= '<button type="button" class="btn btn-' . ($dangerous ? 'danger' : 'primary') . '" data-modal-confirm data-action="' . htmlspecialchars($confirmAction) . '">' . htmlspecialchars($confirmLabel) . '</button>';
        $content .= '</div>';

        return $this->render('confirm', $id, $title, $content, $options);
    }

    public function renderAlert(string $id, string $title, string $message, array $options = []): string
    {
        $type = $options['type'] ?? 'info';
        $dismissLabel = $options['dismissLabel'] ?? 'OK';

        $icon = $this->getAlertIcon($type);
        $content = '<div class="alert-content alert-' . $type . '">';
        $content .= $icon;
        $content .= '<p class="modal-message">' . nl2br(htmlspecialchars($message)) . '</p>';
        $content .= '</div>';
        $content .= '<div class="modal-footer-buttons">';
        $content .= '<button type="button" class="btn btn-primary" data-modal-dismiss>' . htmlspecialchars($dismissLabel) . '</button>';
        $content .= '</div>';

        return $this->render('alert', $id, $title, $content, ['size' => 'small'] + $options);
    }

    public function renderForm(string $id, string $title, string $action, array $fields, array $options = []): string
    {
        $method = $options['method'] ?? 'POST';
        $submitLabel = $options['submitLabel'] ?? 'Submit';
        $cancelLabel = $options['cancelLabel'] ?? 'Cancel';
        $showCsrf = $options['showCsrf'] ?? true;
        $csrfToken = $options['csrfToken'] ?? '';

        $content = '<form id="' . $id . '-form" action="' . htmlspecialchars($action) . '" method="' . $method . '">';
        $content .= '<div class="form-fields">';

        foreach ($fields as $field) {
            $content .= $this->renderFormField($field);
        }

        if ($showCsrf) {
            $content .= '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
        }

        $content .= '</div>';
        $content .= '</form>';
        $content .= '<div class="modal-footer-buttons">';
        $content .= '<button type="button" class="btn btn-secondary" data-modal-cancel>' . htmlspecialchars($cancelLabel) . '</button>';
        $content .= '<button type="submit" form="' . $id . '-form" class="btn btn-primary">' . htmlspecialchars($submitLabel) . '</button>';
        $content .= '</div>';

        return $this->render('form', $id, $title, $content, $options);
    }

    private function renderFormField(array $field): string
    {
        $id = $field['id'] ?? 'field_' . uniqid();
        $name = $field['name'] ?? '';
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? '';
        $value = $field['value'] ?? '';
        $required = $field['required'] ?? false;
        $error = $field['error'] ?? '';
        $placeholder = $field['placeholder'] ?? '';

        $wrapperClass = 'form-field' . ($error ? ' has-error' : '');
        $inputClass = 'form-input' . ($error ? ' is-invalid' : '');

        $html = '<div class="' . $wrapperClass . '">';
        $html .= '<label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label);
        if ($required) {
            $html .= ' <span class="required">*</span>';
        }
        $html .= '</label>';

        if ($type === 'textarea') {
            $html .= '<textarea id="' . $id . '" name="' . $name . '" class="' . $inputClass . '" rows="4">' . htmlspecialchars($value) . '</textarea>';
        } else {
            $html .= '<input type="' . $type . '" id="' . $id . '" name="' . $name . '" value="' . htmlspecialchars($value) . '" class="' . $inputClass . '">';
        }

        if ($error) {
            $html .= '<span class="field-error">' . htmlspecialchars($error) . '</span>';
        }

        $html .= '</div>';
        return $html;
    }

    private function renderFooter(array $options): string
    {
        if (isset($options['footer'])) {
            return '<div class="modal-footer">' . $options['footer'] . '</div>';
        }
        return '';
    }

    private function getAlertIcon(string $type): string
    {
        return match ($type) {
            'success' => '<svg class="alert-icon" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'error' => '<svg class="alert-icon" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'warning' => '<svg class="alert-icon" viewBox="0 0 24 24"><path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'info' => '<svg class="alert-icon" viewBox="0 0 24 24"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            default => '',
        };
    }
}
