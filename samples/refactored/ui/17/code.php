<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedAlertRenderer
{
    /** @var array<string, array{icon: string, class: string}> */
    private array $alertTypes = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeAlertTypes();
    }

    private function initializeAlertTypes(): void
    {
        $this->alertTypes = [
            'success' => [
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                'class' => 'alert-success',
            ],
            'error' => [
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
                'class' => 'alert-error',
            ],
            'warning' => [
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                'class' => 'alert-warning',
            ],
            'info' => [
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                'class' => 'alert-info',
            ],
        ];
    }

    public function render(string $type, string $message, array $options = []): string
    {
        $config = $this->alertTypes[$type] ?? $this->alertTypes['info'];

        $id = $options['id'] ?? 'alert_' . uniqid();
        $title = $options['title'] ?? null;
        $dismissible = $options['dismissible'] ?? true;
        $details = $options['details'] ?? null;
        $duration = $options['duration'] ?? 0;

        $html = '<div';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' class="unified-alert ' . $config['class'] . '"';
        $html .= ' role="alert"';
        if ($duration > 0) {
            $html .= ' data-alert-duration="' . $duration . '"';
        }
        $html .= '>';

        $html .= '<div class="alert-icon-wrapper">' . $config['icon'] . '</div>';
        $html .= '<div class="alert-content">';

        if ($title !== null) {
            $html .= '<h4 class="alert-title">' . htmlspecialchars($title) . '</h4>';
        }

        $html .= '<p class="alert-message">' . nl2br(htmlspecialchars($message)) . '</p>';

        if ($details !== null) {
            $html .= '<details class="alert-details">';
            $html .= '<summary>Show details</summary>';
            $html .= '<pre>' . htmlspecialchars($details) . '</pre>';
            $html .= '</details>';
        }

        if (isset($options['actions']) && is_array($options['actions'])) {
            $html .= '<div class="alert-actions">';
            foreach ($options['actions'] as $action) {
                $html .= '<a href="' . htmlspecialchars($action['url']) . '" class="alert-action alert-action-' . ($action['type'] ?? 'default') . '">';
                $html .= htmlspecialchars($action['label']) . '</a>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        if ($dismissible) {
            $html .= '<button type="button" class="alert-dismiss" aria-label="Dismiss">×</button>';
        }

        $html .= '</div>';

        $this->logger->debug('Rendered unified alert', ['type' => $type, 'id' => $id]);

        return $html;
    }

    public function renderSuccess(string $message, array $options = []): string
    {
        return $this->render('success', $message, $options);
    }

    public function renderError(string $message, array $options = []): string
    {
        return $this->render('error', $message, array_merge(['dismissible' => true], $options));
    }

    public function renderWarning(string $message, array $options = []): string
    {
        return $this->render('warning', $message, $options);
    }

    public function renderInfo(string $message, array $options = []): string
    {
        return $this->render('info', $message, $options);
    }

    public function renderValidationErrors(array $errors): string
    {
        if (empty($errors)) {
            return '';
        }

        $html = '<div class="unified-alert alert-error" role="alert">';
        $html .= '<div class="alert-icon-wrapper">' . $this->alertTypes['error']['icon'] . '</div>';
        $html .= '<div class="alert-content">';
        $html .= '<h4 class="alert-title">Please correct the following errors:</h4>';
        $html .= '<ul class="alert-error-list">';

        foreach ($errors as $error) {
            $html .= '<li>' . htmlspecialchars($error) . '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderToastContainer(array $alerts): string
    {
        $html = '<div class="toast-container toast-container-position-top-right" aria-label="Notifications">';

        foreach ($alerts as $alert) {
            $html .= $this->render($alert['type'], $alert['message'], $alert['options'] ?? []);
        }

        $html .= '</div>';

        return $html;
    }
}
