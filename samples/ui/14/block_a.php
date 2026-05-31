<?php

declare(strict_types=1);

namespace App\View\Modal;

use Psr\Log\LoggerInterface;

final class ConfirmationModalRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderConfirm(string $id, string $title, string $message, ConfirmOptions $options): string
    {
        $html = '<div id="' . htmlspecialchars($id) . '" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title-' . $id . '">';
        $html .= '<div class="modal-container modal-size-' . ($options->size ?? 'medium') . '">';
        $html .= '<div class="modal-header">';
        $html .= '<h2 id="modal-title-' . $id . '" class="modal-title">' . htmlspecialchars($title) . '</h2>';
        $html .= '<button type="button" class="modal-close" aria-label="Close modal" data-modal-close="' . $id . '">×</button>';
        $html .= '</div>';
        $html .= '<div class="modal-body">';
        $html .= '<p class="modal-message">' . htmlspecialchars($message) . '</p>';
        $html .= '</div>';
        $html .= '<div class="modal-footer">';
        $html .= '<button type="button" class="btn btn-secondary" data-modal-cancel="' . $id . '">';
        $html .= htmlspecialchars($options->cancelLabel ?? 'Cancel');
        $html .= '</button>';
        $html .= '<button type="button" class="btn btn-' . ($options->dangerous ? 'danger' : 'primary') . '"';
        $html .= ' data-modal-confirm="' . $id . '"';
        $html .= ' data-confirm-action="' . htmlspecialchars($options->confirmAction ?? '') . '">';
        $html .= htmlspecialchars($options->confirmLabel ?? 'Confirm');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        $this->logger->debug('Rendered confirmation modal', ['id' => $id]);

        return $html;
    }

    public function renderDeleteConfirm(string $id, string $itemName, string $deleteUrl): string
    {
        $options = new ConfirmOptions(
            confirmLabel: 'Delete',
            cancelLabel: 'Keep',
            dangerous: true,
            confirmAction: $deleteUrl,
            size: 'small',
        );

        $message = 'Are you sure you want to delete "' . htmlspecialchars($itemName) . '"? This action cannot be undone.';

        return $this->renderConfirm($id, 'Confirm Deletion', $message, $options);
    }

    public function renderUnsavedChangesModal(string $id): string
    {
        $options = new ConfirmOptions(
            confirmLabel: 'Discard Changes',
            cancelLabel: 'Keep Editing',
            dangerous: true,
            confirmAction: 'discard',
            size: 'small',
        );

        return $this->renderConfirm(
            $id,
            'Unsaved Changes',
            'You have unsaved changes. Are you sure you want to leave? Your changes will be lost.',
            $options
        );
    }
}
