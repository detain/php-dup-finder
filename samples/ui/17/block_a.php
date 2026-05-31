<?php

declare(strict_types=1);

namespace App\View\Alert;

use Psr\Log\LoggerInterface;

final class FormAlertRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderSuccess(string $message, array $options = []): string
    {
        $dismissible = $options['dismissible'] ?? true;
        $title = $options['title'] ?? 'Success';
        $icon = $this->getSuccessIcon();

        $html = '<div class="alert alert-success alert-component" role="alert"';
        if ($dismissible) {
            $html .= ' data-alert-dismissible="true"';
        }
        $html .= '>';
        $html .= '<div class="alert-icon-wrapper">' . $icon . '</div>';
        $html .= '<div class="alert-content">';
        if ($title) {
            $html .= '<h4 class="alert-title">' . htmlspecialchars($title) . '</h4>';
        }
        $html .= '<p class="alert-message">' . nl2br(htmlspecialchars($message)) . '</p>';
        $html .= '</div>';
        if ($dismissible) {
            $html .= '<button type="button" class="alert-dismiss" aria-label="Dismiss alert">×</button>';
        }
        $html .= '</div>';

        $this->logger->debug('Rendered success alert');

        return $html;
    }

    public function renderError(string $message, array $options = []): string
    {
        $dismissible = $options['dismissible'] ?? true;
        $title = $options['title'] ?? 'Error';
        $icon = $this->getErrorIcon();
        $details = $options['details'] ?? null;

        $html = '<div class="alert alert-error alert-component" role="alert"';
        if ($dismissible) {
            $html .= ' data-alert-dismissible="true"';
        }
        $html .= '>';
        $html .= '<div class="alert-icon-wrapper">' . $icon . '</div>';
        $html .= '<div class="alert-content">';
        if ($title) {
            $html .= '<h4 class="alert-title">' . htmlspecialchars($title) . '</h4>';
        }
        $html .= '<p class="alert-message">' . nl2br(htmlspecialchars($message)) . '</p>';
        if ($details !== null) {
            $html .= '<details class="alert-details">';
            $html .= '<summary>Show details</summary>';
            $html .= '<pre class="alert-details-content">' . htmlspecialchars($details) . '</pre>';
            $html .= '</details>';
        }
        $html .= '</div>';
        if ($dismissible) {
            $html .= '<button type="button" class="alert-dismiss" aria-label="Dismiss alert">×</button>';
        }
        $html .= '</div>';

        $this->logger->debug('Rendered error alert');

        return $html;
    }

    public function renderWarning(string $message, array $options = []): string
    {
        $dismissible = $options['dismissible'] ?? true;
        $title = $options['title'] ?? 'Warning';
        $icon = $this->getWarningIcon();

        $html = '<div class="alert alert-warning alert-component" role="alert"';
        if ($dismissible) {
            $html .= ' data-alert-dismissible="true"';
        }
        $html .= '>';
        $html .= '<div class="alert-icon-wrapper">' . $icon . '</div>';
        $html .= '<div class="alert-content">';
        if ($title) {
            $html .= '<h4 class="alert-title">' . htmlspecialchars($title) . '</h4>';
        }
        $html .= '<p class="alert-message">' . nl2br(htmlspecialchars($message)) . '</p>';
        $html .= '</div>';
        if ($dismissible) {
            $html .= '<button type="button" class="alert-dismiss" aria-label="Dismiss alert">×</button>';
        }
        $html .= '</div>';

        $this->logger->debug('Rendered warning alert');

        return $html;
    }

    public function renderInfo(string $message, array $options = []): string
    {
        $dismissible = $options['dismissible'] ?? true;
        $title = $options['title'] ?? null;
        $icon = $this->getInfoIcon();

        $html = '<div class="alert alert-info alert-component" role="status"';
        if ($dismissible) {
            $html .= ' data-alert-dismissible="true"';
        }
        $html .= '>';
        $html .= '<div class="alert-icon-wrapper">' . $icon . '</div>';
        $html .= '<div class="alert-content">';
        if ($title) {
            $html .= '<h4 class="alert-title">' . htmlspecialchars($title) . '</h4>';
        }
        $html .= '<p class="alert-message">' . nl2br(htmlspecialchars($message)) . '</p>';
        $html .= '</div>';
        if ($dismissible) {
            $html .= '<button type="button" class="alert-dismiss" aria-label="Dismiss alert">×</button>';
        }
        $html .= '</div>';

        $this->logger->debug('Rendered info alert');

        return $html;
    }

    public function renderValidationErrors(array $errors): string
    {
        if (empty($errors)) {
            return '';
        }

        $html = '<div class="alert alert-validation alert-component" role="alert">';
        $html .= '<div class="alert-icon-wrapper">' . $this->getErrorIcon() . '</div>';
        $html .= '<div class="alert-content">';
        $html .= '<h4 class="alert-title">Please correct the following errors:</h4>';
        $html .= '<ul class="alert-error-list">';

        foreach ($errors as $error) {
            $html .= '<li class="error-item">' . htmlspecialchars($error) . '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function getSuccessIcon(): string
    {
        return '<svg class="alert-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    private function getErrorIcon(): string
    {
        return '<svg class="alert-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    private function getWarningIcon(): string
    {
        return '<svg class="alert-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    private function getInfoIcon(): string
    {
        return '<svg class="alert-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
}
