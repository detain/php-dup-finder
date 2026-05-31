<?php

declare(strict_types=1);

namespace App\View\Pagination;

use Psr\Log\LoggerInterface;

final class SearchPaginationRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderPagination(PaginationData $data, string $baseUrl): string
    {
        if ($data->totalPages <= 1) {
            return '';
        }

        $html = '<div class="search-pagination pagination-container">';
        $html .= '<div class="pagination-summary">';
        $html .= 'Showing results ' . $data->fromItem . '-' . $data->toItem . ' of ' . $data->totalItems;
        $html .= '</div>';
        $html .= '<nav class="pagination-nav" aria-label="Search results pagination">';
        $html .= '<ul class="pagination-list">';

        if ($data->currentPage > 1) {
            $html .= $this->renderPageLink($data->currentPage - 1, 'Previous', $baseUrl, false);
        } else {
            $html .= $this->renderDisabledPageLink('Previous');
        }

        $html .= $this->renderPageNumbers($data, $baseUrl);

        if ($data->currentPage < $data->totalPages) {
            $html .= $this->renderPageLink($data->currentPage + 1, 'Next', $baseUrl, false);
        } else {
            $html .= $this->renderDisabledPageLink('Next');
        }

        $html .= '</ul>';
        $html .= '</nav>';
        $html .= '</div>';

        $this->logger->debug('Rendered search pagination', [
            'current_page' => $data->currentPage,
            'total_pages' => $data->totalPages,
        ]);

        return $html;
    }

    private function renderPageNumbers(PaginationData $data, string $baseUrl): string
    {
        $html = '';
        $start = max(1, $data->currentPage - 2);
        $end = min($data->totalPages, $data->currentPage + 2);

        if ($start > 1) {
            $html .= $this->renderPageLink(1, '1', $baseUrl, false);
            if ($start > 2) {
                $html .= '<li class="pagination-ellipsis" aria-hidden="true"><span>...</span></li>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $data->currentPage) {
                $html .= '<li class="pagination-item active" aria-current="page"><span>' . $i . '</span></li>';
            } else {
                $html .= $this->renderPageLink($i, (string) $i, $baseUrl, false);
            }
        }

        if ($end < $data->totalPages) {
            if ($end < $data->totalPages - 1) {
                $html .= '<li class="pagination-ellipsis" aria-hidden="true"><span>...</span></li>';
            }
            $html .= $this->renderPageLink($data->totalPages, (string) $data->totalPages, $baseUrl, false);
        }

        return $html;
    }

    private function renderPageLink(int $page, string $label, string $baseUrl, bool $isActive): string
    {
        $url = $baseUrl . '?page=' . $page;
        return '<li class="pagination-item"><a href="' . htmlspecialchars($url) . '" class="pagination-link">' . htmlspecialchars($label) . '</a></li>';
    }

    private function renderDisabledPageLink(string $label): string
    {
        return '<li class="pagination-item disabled" aria-disabled="true"><span class="pagination-link">' . htmlspecialchars($label) . '</span></li>';
    }

    public function renderPerPageSelector(int $currentPerPage, string $baseUrl): string
    {
        $options = [10, 25, 50, 100];

        $html = '<div class="per-page-selector">';
        $html .= '<label for="per-page-select">Results per page:</label>';
        $html .= '<select id="per-page-select" class="per-page-select" data-base-url="' . htmlspecialchars($baseUrl) . '">';

        foreach ($options as $option) {
            $selected = $option === $currentPerPage ? ' selected' : '';
            $html .= '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }
}
