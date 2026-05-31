<?php

declare(strict_types=1);

namespace App\View\Pagination;

use Psr\Log\LoggerInterface;

final class ContentPaginationRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderPagination(PaginationData $data, string $basePath): string
    {
        if ($data->totalPages <= 1 && $data->totalItems <= $data->perPage) {
            return '';
        }

        $html = '<div class="content-pagination clearfix">';
        $html .= '<div class="content-pagination-info">';
        $html .= 'Page ' . $data->currentPage . ' of ' . $data->totalPages;
        $html .= ' (' . $data->totalItems . ' total items)';
        $html .= '</div>';
        $html .= '<div class="content-pagination-links">';

        $html .= $this->renderFirstLink($data, $basePath);
        $html .= $this->renderPreviousLink($data, $basePath);
        $html .= $this->renderNumberedLinks($data, $basePath);
        $html .= $this->renderNextLink($data, $basePath);
        $html .= $this->renderLastLink($data, $basePath);

        $html .= '</div>';
        $html .= '</div>';

        $this->logger->debug('Rendered content pagination', [
            'current_page' => $data->currentPage,
            'total_pages' => $data->totalPages,
        ]);

        return $html;
    }

    private function renderFirstLink(PaginationData $data, string $basePath): string
    {
        if ($data->currentPage <= 1) {
            return '<span class="page-link disabled first-link">« First</span>';
        }
        return '<a href="' . htmlspecialchars($basePath) . '?page=1" class="page-link first-link" rel="first">« First</a>';
    }

    private function renderPreviousLink(PaginationData $data, string $basePath): string
    {
        if ($data->currentPage <= 1) {
            return '<span class="page-link disabled prev-link">‹ Prev</span>';
        }
        $url = $basePath . '?page=' . ($data->currentPage - 1);
        return '<a href="' . htmlspecialchars($url) . '" class="page-link prev-link" rel="prev">‹ Prev</a>';
    }

    private function renderNumberedLinks(PaginationData $data, string $basePath): string
    {
        $html = '';
        $start = max(1, $data->currentPage - 2);
        $end = min($data->totalPages, $data->currentPage + 2);

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $data->currentPage) {
                $html .= '<span class="page-link current" aria-current="page">' . $i . '</span>';
            } else {
                $url = $basePath . '?page=' . $i;
                $html .= '<a href="' . htmlspecialchars($url) . '" class="page-link numbered">' . $i . '</a>';
            }
        }

        return $html;
    }

    private function renderNextLink(PaginationData $data, string $basePath): string
    {
        if ($data->currentPage >= $data->totalPages) {
            return '<span class="page-link disabled next-link">Next ›</span>';
        }
        $url = $basePath . '?page=' . ($data->currentPage + 1);
        return '<a href="' . htmlspecialchars($url) . '" class="page-link next-link" rel="next">Next ›</a>';
    }

    private function renderLastLink(PaginationData $data, string $basePath): string
    {
        if ($data->currentPage >= $data->totalPages) {
            return '<span class="page-link disabled last-link">Last »</span>';
        }
        $url = $basePath . '?page=' . $data->totalPages;
        return '<a href="' . htmlspecialchars($url) . '" class="page-link last-link" rel="last">Last »</a>';
    }

    public function renderJumpToPage(int $currentPage, int $totalPages, string $basePath): string
    {
        $html = '<div class="jump-to-page">';
        $html .= '<form method="get" action="' . htmlspecialchars($basePath) . '" class="jump-form">';
        $html .= '<label for="jump-page-input">Go to page:</label>';
        $html .= '<input type="number" id="jump-page-input" name="page" min="1" max="' . $totalPages . '" value="' . $currentPage . '" class="jump-input" />';
        $html .= '<button type="submit" class="jump-button">Go</button>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }
}
