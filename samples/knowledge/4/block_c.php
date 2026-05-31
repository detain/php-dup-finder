<?php

declare(strict_types=1);

namespace App\Admin\Ui;

final class UploadHintRenderer
{
    public function renderField(string $name, string $label, ?string $accept = null): string
    {
        $html  = '<div class="form-group upload-field">';
        $html .= '<label for="' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';

        $attrs = [
            'type="file"',
            'id="' . htmlspecialchars($name) . '"',
            'name="' . htmlspecialchars($name) . '"',
            'data-max-bytes="10485760"',
        ];
        if ($accept !== null) {
            $attrs[] = 'accept="' . htmlspecialchars($accept) . '"';
        }

        $html .= '<input ' . implode(' ', $attrs) . '>';
        $html .= '<p class="hint">';
        $html .= 'Maximum file size: 10 MB. ';
        if ($accept !== null) {
            $html .= 'Allowed types: ' . htmlspecialchars($accept) . '.';
        }
        $html .= '</p>';

        $html .= '<div class="upload-progress" data-progress-for="' . htmlspecialchars($name) . '"></div>';
        $html .= '<small class="muted">Files larger than 10 MB will be rejected by the server.</small>';
        $html .= '</div>';

        return $html;
    }

    public function renderInlineLimitBanner(): string
    {
        return '<div class="banner banner--info">Uploads are limited to 10 MB per file.</div>';
    }
}
