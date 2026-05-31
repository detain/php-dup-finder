<?php

declare(strict_types=1);

namespace App\View\Modal;

use Psr\Log\LoggerInterface;

final class AlertModalRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderAlert(string $id, string $title, string $message, AlertOptions $options): string
    {
        $iconSvg = $this->getIconForType($options->type);

        $html = '<div id="' . htmlspecialchars($id) . '" class="modal-overlay alert-modal" role="alertdialog" aria-modal="true">';
        $html .= '<div class="modal-container modal-size-' . ($options->size ?? 'small') . '">';
        $html .= '<div class="modal-header alert-header alert-' . $options->type . '">';
        $html .= '<div class="alert-icon">' . $iconSvg . '</div>';
        $html .= '<h2 class="modal-title">' . htmlspecialchars($title) . '</h2>';
        $html .= '<button type="button" class="modal-close" aria-label="Close" data-modal-close="' . $id . '">×</button>';
        $html .= '</div>';
        $html .= '<div class="modal-body">';
        $html .= '<p class="modal-message">' . nl2br(htmlspecialchars($message)) . '</p>';
        if (!empty($options->details)) {
            $html .= '<details class="alert-details">';
            $html .= '<summary>More details</summary>';
            $html .= '<pre>' . htmlspecialchars($options->details) . '</pre>';
            $html .= '</details>';
        }
        $html .= '</div>';
        $html .= '<div class="modal-footer">';
        $html .= '<button type="button" class="btn btn-' . $this->getButtonTypeForAlert($options->type) . '"';
        $html .= ' data-modal-dismiss="' . $id . '">';
        $html .= htmlspecialchars($options->dismissLabel ?? 'OK');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        $this->logger->debug('Rendered alert modal', ['id' => $id, 'type' => $options->type]);

        return $html;
    }

    public function renderSuccess(string $id, string $title, string $message): string
    {
        return $this->renderAlert($id, $title, $message, new AlertOptions(type: 'success'));
    }

    public function renderError(string $id, string $title, string $message, ?string $details = null): string
    {
        return $this->renderAlert($id, $title, $message, new AlertOptions(type: 'error', details: $details));
    }

    public function renderWarning(string $id, string $title, string $message): string
    {
        return $this->renderAlert($id, $title, $message, new AlertOptions(type: 'warning'));
    }

    private function getIconForType(string $type): string
    {
        return match ($type) {
            'success' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'error' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
            'warning' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'info' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            default => '',
        };
    }

    private function getButtonTypeForAlert(string $type): string
    {
        return match ($type) {
            'success' => 'primary',
            'error' => 'danger',
            'warning' => 'secondary',
            'info' => 'primary',
            default => 'secondary',
        };
    }
}
