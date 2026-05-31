<?php

declare(strict_types=1);

namespace App\View\Pagination;

use Psr\Log\LoggerInterface;

final class AdminTablePaginationRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderPagination(PaginationData $data, string $baseUrl, array $queryParams = []): string
    {
        if ($data->totalPages <= 1) {
            return '';
        }

        $html = '<div class="admin-table-pagination table-pagination-wrapper">';
        $html .= '<div class="pagination-info">';
        $html .= '<span class="info-count">' . $data->fromItem . ' to ' . $data->toItem . '</span>';
        $html .= '<span class="info-separator">/</span>';
        $html .= '<span class="info-total">' . $data->totalItems . ' records</span>';
        $html .= '</div>';
        $html .= '<ul class="admin-pagination-list">';

        if ($data->currentPage > 1) {
            $prevUrl = $this->buildUrl($baseUrl, $data->currentPage - 1, $queryParams);
            $html .= '<li class="pag-item"><a href="' . htmlspecialchars($prevUrl) . '" class="pag-link pag-prev" aria-label="Previous page">‹</a></li>';
        } else {
            $html .= '<li class="pag-item disabled"><span class="pag-link pag-prev" aria-disabled="true">‹</span></li>';
        }

        $html .= $this->renderPageNumbers($data, $baseUrl, $queryParams);

        if ($data->currentPage < $data->totalPages) {
            $nextUrl = $this->buildUrl($baseUrl, $data->currentPage + 1, $queryParams);
            $html .= '<li class="pag-item"><a href="' . htmlspecialchars($nextUrl) . '" class="pag-link pag-next" aria-label="Next page">›</a></li>';
        } else {
            $html .= '<li class="pag-item disabled"><span class="pag-link pag-next" aria-disabled="true">›</span></li>';
        }

        $html .= '</ul>';
        $html .= '</div>';

        $this->logger->debug('Rendered admin table pagination', [
            'current_page' => $data->currentPage,
            'total_pages' => $data->totalPages,
        ]);

        return $html;
    }

    private function renderPageNumbers(PaginationData $data, string $baseUrl, array $queryParams): string
    {
        $html = '';
        $range = $this->getPageRange($data->currentPage, $data->totalPages);

        foreach ($range as $page) {
            if ($page === '...') {
                $html .= '<li class="pag-ellipsis" aria-hidden="true"><span>...</span></li>';
            } elseif ($page === $data->currentPage) {
                $html .= '<li class="pag-item current"><span class="pag-link active" aria-current="page">' . $page . '</span></li>';
            } else {
                $url = $this->buildUrl($baseUrl, (int) $page, $queryParams);
                $html .= '<li class="pag-item"><a href="' . htmlspecialchars($url) . '" class="pag-link">' . $page . '</a></li>';
            }
        }

        return $html;
    }

    private function getPageRange(int $currentPage, int $totalPages): array
    {
        $range = [];
        $showFrom = max(1, $currentPage - 1);
        $showTo = min($totalPages, $currentPage + 1);

        if ($showFrom > 1) {
            $range[] = 1;
            if ($showFrom > 2) {
                $range[] = '...';
            }
        }

        for ($i = $showFrom; $i <= $showTo; $i++) {
            $range[] = $i;
        }

        if ($showTo < $totalPages) {
            if ($showTo < $totalPages - 1) {
                $range[] = '...';
            }
            $range[] = $totalPages;
        }

        return $range;
    }

    private function buildUrl(string $baseUrl, int $page, array $queryParams): string
    {
        $params = $queryParams;
        $params['page'] = $page;
        return $baseUrl . '?' . http_build_query($params);
    }

    public function renderPageSizeDropdown(int $currentSize, string $baseUrl): string
    {
        $sizes = [15, 25, 50, 100];

        $html = '<div class="page-size-dropdown">';
        $html .= '<span class="dropdown-label">Show</span>';
        $html .= '<select class="page-size-select" data-url="' . htmlspecialchars($baseUrl) . '">';

        foreach ($sizes as $size) {
            $selected = $size === $currentSize ? ' selected' : '';
            $html .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
        }

        $html .= '</select>';
        $html .= '<span class="dropdown-suffix">entries</span>';
        $html .= '</div>';

        return $html;
    }
}
