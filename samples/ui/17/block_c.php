<?php

declare(strict_types=1);

namespace App\View\Alert;

use Psr\Log\LoggerInterface;

final class ToastNotificationRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderToast(string $type, string $message, array $options = []): string
    {
        $toastId = $options['id'] ?? 'toast_' . uniqid();
        $duration = $options['duration'] ?? 5000;
        $title = $options['title'] ?? null;
        $icon = $this->getIconForType($type);

        $html = '<div id="' . $toastId . '" class="toast-notification toast-' . $type . '" role="alert" aria-live="assertive"';
        if ($duration > 0) {
            $html .= ' data-toast-duration="' . $duration . '"';
        }
        $html .= '>';
        $html .= '<div class="toast-icon-wrapper">' . $icon . '</div>';
        $html .= '<div class="toast-content">';
        if ($title) {
            $html .= '<div class="toast-title">' . htmlspecialchars($title) . '</div>';
        }
        $html .= '<div class="toast-message">' . nl2br(htmlspecialchars($message)) . '</div>';
        $html .= '</div>';
        $html .= '<button type="button" class="toast-close" aria-label="Close notification">×</button>';
        $html .= '</div>';

        $this->logger->debug('Rendered toast notification', ['type' => $type, 'id' => $toastId]);

        return $html;
    }

    public function renderSuccessToast(string $message, array $options = []): string
    {
        return $this->renderToast('success', $message, $options);
    }

    public function renderErrorToast(string $message, array $options = []): string
    {
        return $this->renderToast('error', $message, array_merge(['duration' => 8000], $options));
    }

    public function renderWarningToast(string $message, array $options = []): string
    {
        return $this->renderToast('warning', $message, $options);
    }

    public function renderInfoToast(string $message, array $options = []): string
    {
        return $this->renderToast('info', $message, $options);
    }

    public function renderToastContainer(array $toasts): string
    {
        $html = '<div class="toast-container toast-container-position-top-right" aria-label="Notifications">';

        foreach ($toasts as $toast) {
            $type = $toast['type'] ?? 'info';
            $message = $toast['message'] ?? '';
            $options = $toast['options'] ?? [];
            $html .= $this->renderToast($type, $message, $options);
        }

        $html .= '</div>';

        return $html;
    }

    private function getIconForType(string $type): string
    {
        return match ($type) {
            'success' => '<svg class="toast-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'error' => '<svg class="toast-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'warning' => '<svg class="toast-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'info' => '<svg class="toast-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            default => '',
        };
    }
}
