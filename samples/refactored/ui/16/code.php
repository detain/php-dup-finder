<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedPaginationRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function render(PaginationData $data, string $baseUrl, array $options = []): string
    {
        if ($data->totalPages <= 1) {
            return '';
        }

        $style = $options['style'] ?? 'default';
        $showSummary = $options['showSummary'] ?? true;
        $showPageSize = $options['showPageSize'] ?? false;

        $html = '<div class="pagination pagination-' . $style . '">';

        if ($showSummary) {
            $html .= '<div class="pagination-summary">';
            $html .= 'Showing ' . $data->fromItem . '-' . $data->toItem . ' of ' . $data->totalItems;
            $html .= '</div>';
        }

        $html .= '<nav class="pagination-nav" aria-label="Pagination">';
        $html .= '<ul class="pagination-list">';

        $html .= $this->renderPreviousButton($data, $baseUrl);

        $html .= $this->renderPageNumberButtons($data, $baseUrl, $options);

        $html .= $this->renderNextButton($data, $baseUrl);

        $html .= '</ul>';
        $html .= '</nav>';

        if ($showPageSize) {
            $html .= $this->renderPageSizeSelector($data->perPage, $baseUrl);
        }

        $html .= '</div>';

        $this->logger->debug('Rendered pagination', [
            'current_page' => $data->currentPage,
            'total_pages' => $data->totalPages,
            'style' => $style,
        ]);

        return $html;
    }

    private function renderPreviousButton(PaginationData $data, string $baseUrl): string
    {
        if ($data->currentPage > 1) {
            $url = $this->buildPageUrl($baseUrl, $data->currentPage - 1);
            return '<li class="pagination-item"><a href="' . htmlspecialchars($url) . '" class="pagination-link" rel="prev">Previous</a></li>';
        }
        return '<li class="pagination-item disabled" aria-disabled="true"><span class="pagination-link">Previous</span></li>';
    }

    private function renderNextButton(PaginationData $data, string $baseUrl): string
    {
        if ($data->currentPage < $data->totalPages) {
            $url = $this->buildPageUrl($baseUrl, $data->currentPage + 1);
            return '<li class="pagination-item"><a href="' . htmlspecialchars($url) . '" class="pagination-link" rel="next">Next</a></li>';
        }
        return '<li class="pagination-item disabled" aria-disabled="true"><span class="pagination-link">Next</span></li>';
    }

    private function renderPageNumberButtons(PaginationData $data, string $baseUrl, array $options): string
    {
        $html = '';
        $window = $options['windowSize'] ?? 2;
        $showEdges = $options['showEdges'] ?? true;

        $start = max(1, $data->currentPage - $window);
        $end = min($data->totalPages, $data->currentPage + $window);

        if ($showEdges && $start > 1) {
            $html .= '<li class="pagination-item"><a href="' . htmlspecialchars($this->buildPageUrl($baseUrl, 1)) . '" class="pagination-link">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="pagination-ellipsis" aria-hidden="true"><span>...</span></li>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $data->currentPage) {
                $html .= '<li class="pagination-item active" aria-current="page"><span class="pagination-link current">' . $i . '</span></li>';
            } else {
                $url = $this->buildPageUrl($baseUrl, $i);
                $html .= '<li class="pagination-item"><a href="' . htmlspecialchars($url) . '" class="pagination-link">' . $i . '</a></li>';
            }
        }

        if ($showEdges && $end < $data->totalPages) {
            if ($end < $data->totalPages - 1) {
                $html .= '<li class="pagination-ellipsis" aria-hidden="true"><span>...</span></li>';
            }
            $html .= '<li class="pagination-item"><a href="' . htmlspecialchars($this->buildPageUrl($baseUrl, $data->totalPages)) . '" class="pagination-link">' . $data->totalPages . '</a></li>';
        }

        return $html;
    }

    private function renderPageSizeSelector(int $currentSize, string $baseUrl): string
    {
        $sizes = [10, 25, 50, 100];

        $html = '<div class="pagination-page-size">';
        $html .= '<label>Show:</label>';
        $html .= '<select class="page-size-select" data-url="' . htmlspecialchars($baseUrl) . '">';

        foreach ($sizes as $size) {
            $selected = $size === $currentSize ? ' selected' : '';
            $html .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    private function buildPageUrl(string $baseUrl, int $page): string
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        return $baseUrl . $separator . 'page=' . $page;
    }
}
