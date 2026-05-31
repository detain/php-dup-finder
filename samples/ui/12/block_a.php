<?php

declare(strict_types=1);

namespace App\View\Table;

use App\Entity\User;
use Psr\Log\LoggerInterface;

final class UserTableRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderHeaders(): string
    {
        $columns = [
            ['key' => 'id', 'label' => 'ID', 'sortable' => true, 'width' => 60],
            ['key' => 'name', 'label' => 'Name', 'sortable' => true, 'width' => 200],
            ['key' => 'email', 'label' => 'Email', 'sortable' => true, 'width' => 250],
            ['key' => 'status', 'label' => 'Status', 'sortable' => true, 'width' => 100],
            ['key' => 'created', 'label' => 'Created', 'sortable' => true, 'width' => 120],
            ['key' => 'actions', 'label' => '', 'sortable' => false, 'width' => 80],
        ];

        $html = '<thead><tr>';
        foreach ($columns as $column) {
            $sortIndicator = $column['sortable'] ? '<span class="sort-indicator">↕</span>' : '';
            $thClass = $column['sortable'] ? 'th-sortable' : '';
            $html .= '<th class="' . $thClass . '" data-column="' . $column['key'] . '" style="width:' . $column['width'] . 'px">';
            $html .= htmlspecialchars($column['label']) . $sortIndicator;
            $html .= '</th>';
        }
        $html .= '</tr></thead>';

        return $html;
    }

    public function renderRow(User $user): string
    {
        $statusClass = match ($user->getStatus()) {
            'active' => 'status-active',
            'inactive' => 'status-inactive',
            'pending' => 'status-pending',
            default => '',
        };

        $html = '<tr data-user-id="' . $user->getId() . '">';
        $html .= '<td class="cell-id">' . $user->getId() . '</td>';
        $html .= '<td class="cell-name">' . htmlspecialchars($user->getFullName()) . '</td>';
        $html .= '<td class="cell-email">' . htmlspecialchars($user->getEmail()) . '</td>';
        $html .= '<td class="cell-status"><span class="status-badge ' . $statusClass . '">' . htmlspecialchars($user->getStatus()) . '</span></td>';
        $html .= '<td class="cell-date">' . $user->getCreatedAt()->format('Y-m-d') . '</td>';
        $html .= '<td class="cell-actions">';
        $html .= '<a href="/users/' . $user->getId() . '/edit" class="action-edit" title="Edit">Edit</a>';
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    public function renderTable(array $users, array $options = []): string
    {
        $html = '<div class="table-container">';
        $html .= '<table class="data-table user-table"';

        if (!empty($options['sortColumn'])) {
            $html .= ' data-sort-column="' . htmlspecialchars($options['sortColumn']) . '"';
            $html .= ' data-sort-direction="' . htmlspecialchars($options['sortDirection'] ?? 'asc') . '"';
        }

        $html .= '>';
        $html .= $this->renderHeaders();

        $html .= '<tbody>';
        if (empty($users)) {
            $html .= '<tr><td colspan="6" class="empty-row">No users found</td></tr>';
        } else {
            foreach ($users as $user) {
                $html .= $this->renderRow($user);
            }
        }
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        $this->logger->debug('Rendered user table', ['row_count' => count($users)]);

        return $html;
    }

    public function renderPagination(int $currentPage, int $totalPages, int $perPage, int $totalCount): string
    {
        $html = '<div class="table-pagination">';
        $html .= '<span class="pagination-info">Showing ' . (($currentPage - 1) * $perPage + 1) . '-' . min($currentPage * $perPage, $totalCount) . ' of ' . $totalCount . '</span>';
        $html .= '<div class="pagination-links">';

        if ($currentPage > 1) {
            $html .= '<a href="?page=' . ($currentPage - 1) . '" class="page-link prev">Previous</a>';
        }

        for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
            $activeClass = $i === $currentPage ? ' active' : '';
            $html .= '<a href="?page=' . $i . '" class="page-link' . $activeClass . '">' . $i . '</a>';
        }

        if ($currentPage < $totalPages) {
            $html .= '<a href="?page=' . ($currentPage + 1) . '" class="page-link next">Next</a>';
        }

        $html .= '</div></div>';

        return $html;
    }
}
