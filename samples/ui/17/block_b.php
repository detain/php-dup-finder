<?php

declare(strict_types=1);

namespace App\View\Alert;

use Psr\Log\LoggerInterface;

final class SystemAlertRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderAlert(string $type, string $message, array $options = []): string
    {
        $html = '<div class="system-alert alert-type-' . $type . '" role="alert" aria-live="polite">';
        $html .= '<div class="alert-banner">';
        $html .= '<div class="alert-icon-container">' . $this->getIconForType($type) . '</div>';
        $html .= '<div class="alert-body">';
        $html .= '<p class="alert-text">' . nl2br(htmlspecialchars($message)) . '</p>';

        if (isset($options['actions']) && is_array($options['actions'])) {
            $html .= '<div class="alert-actions">';
            foreach ($options['actions'] as $action) {
                $html .= '<a href="' . htmlspecialchars($action['url']) . '" class="alert-action alert-action-' . ($action['type'] ?? 'default') . '">';
                $html .= htmlspecialchars($action['label']) . '</a>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        if ($options['dismissible'] ?? true) {
            $html .= '<button type="button" class="alert-close-btn" aria-label="Close">×</button>';
        }

        $html .= '</div>';
        $html .= '</div>';

        $this->logger->debug('Rendered system alert', ['type' => $type]);

        return $html;
    }

    public function renderMaintenanceBanner(string $message, string $endTime = null): string
    {
        $html = '<div class="system-alert alert-type-warning alert-maintenance" role="alert">';
        $html .= '<div class="alert-banner">';
        $html .= '<div class="alert-icon-container">' . $this->getIconForType('warning') . '</div>';
        $html .= '<div class="alert-body">';
        $html .= '<p class="alert-title">Scheduled Maintenance</p>';
        $html .= '<p class="alert-text">' . nl2br(htmlspecialchars($message)) . '</p>';
        if ($endTime) {
            $html .= '<p class="alert-meta">Expected completion: ' . htmlspecialchars($endTime) . '</p>';
        }
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderOutageNotification(string $service, string $message): string
    {
        $html = '<div class="system-alert alert-type-error alert-outage" role="alert">';
        $html .= '<div class="alert-banner">';
        $html .= '<div class="alert-icon-container">' . $this->getIconForType('error') . '</div>';
        $html .= '<div class="alert-body">';
        $html .= '<p class="alert-title">Service Disruption: ' . htmlspecialchars($service) . '</p>';
        $html .= '<p class="alert-text">' . nl2br(htmlspecialchars($message)) . '</p>';
        $html .= '<p class="alert-meta">Our team is investigating. Updates will be posted here.</p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderSuccessNotification(string $title, string $message): string
    {
        $html = '<div class="system-alert alert-type-success" role="status">';
        $html .= '<div class="alert-banner">';
        $html .= '<div class="alert-icon-container">' . $this->getIconForType('success') . '</div>';
        $html .= '<div class="alert-body">';
        $html .= '<p class="alert-title">' . htmlspecialchars($title) . '</p>';
        $html .= '<p class="alert-text">' . nl2br(htmlspecialchars($message)) . '</p>';
        $html .= '</div>';
        $html .= '<button type="button" class="alert-close-btn" aria-label="Dismiss">×</button>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function getIconForType(string $type): string
    {
        return match ($type) {
            'success' => '<svg class="alert-icon-svg" viewBox="0 0 24 24" fill="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'error' => '<svg class="alert-icon-svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
            'warning' => '<svg class="alert-icon-svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'info' => '<svg class="alert-icon-svg" viewBox="0 0 24 24" fill="currentColor"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            default => '',
        };
    }
}
