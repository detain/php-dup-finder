<?php
// app/View/DataTable.php
namespace App\View;

final class DataTable
{
    /**
     * @param array<string,string>           $columns      col_key => label
     * @param iterable<array<string,mixed>>  $rows
     * @param callable(array<string,mixed>): string $rowRenderer
     */
    public static function render(
        string $title,
        array $columns,
        iterable $rows,
        callable $rowRenderer,
        int $page,
        int $pages,
        string $sort,
        string $dir
    ): void {
        echo '<h2>' . htmlspecialchars($title) . '</h2>';
        echo '<table class="data-table"><thead><tr>';
        foreach ($columns as $col => $label) {
            $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
            $arrow  = $sort === $col ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '';
            printf('<th><a href="?sort=%s&dir=%s&page=1">%s%s</a></th>',
                   $col, $newDir, htmlspecialchars($label), $arrow);
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo $rowRenderer($row);
        }
        echo '</tbody></table>';

        echo '<nav class="pager"><ul>';
        $prev = max(1, $page - 1);
        $next = min($pages, $page + 1);
        printf('<li class="%s"><a href="?page=%d&sort=%s&dir=%s">&laquo; Prev</a></li>',
               $page === 1 ? 'disabled' : '', $prev, $sort, $dir);
        for ($i = 1; $i <= $pages; $i++) {
            printf('<li class="%s"><a href="?page=%d&sort=%s&dir=%s">%d</a></li>',
                   $i === $page ? 'active' : '', $i, $sort, $dir, $i);
        }
        printf('<li class="%s"><a href="?page=%d&sort=%s&dir=%s">Next &raquo;</a></li>',
               $page === $pages ? 'disabled' : '', $next, $sort, $dir);
        echo '</ul></nav>';
    }
}

// Call sites collapse to a column map + row renderer closure per page, e.g.:
// DataTable::render('Orders', $cols, $orders, fn($r) => sprintf('<tr>...</tr>', ...), $page, $pages, $sort, $dir);
