<?php
// admin/orders.php
require __DIR__ . '/../bootstrap.php';

$page    = max(1, (int) ($_GET['page'] ?? 1));
$sort    = $_GET['sort'] ?? 'created_at';
$dir     = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$perPage = 20;

$orders = $db->fetchAll(
    "SELECT id, customer, total, status, created_at FROM orders ORDER BY {$sort} {$dir} LIMIT ?, ?",
    [($page - 1) * $perPage, $perPage]
);
$total  = (int) $db->fetchOne("SELECT COUNT(*) FROM orders");
$pages  = (int) ceil($total / $perPage);

echo '<h2>Orders</h2>';
echo '<table class="data-table"><thead><tr>';
foreach (['id' => 'ID', 'customer' => 'Customer', 'total' => 'Total', 'status' => 'Status', 'created_at' => 'Created'] as $col => $label) {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow  = $sort === $col ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '';
    printf(
        '<th><a href="?sort=%s&dir=%s&page=1">%s%s</a></th>',
        $col, $newDir, htmlspecialchars($label), $arrow
    );
}
echo '</tr></thead><tbody>';
foreach ($orders as $row) {
    printf(
        '<tr><td>%d</td><td>%s</td><td>$%.2f</td><td><span class="badge badge-%s">%s</span></td><td>%s</td></tr>',
        $row['id'],
        htmlspecialchars($row['customer']),
        (float) $row['total'],
        htmlspecialchars($row['status']),
        htmlspecialchars(ucfirst($row['status'])),
        htmlspecialchars($row['created_at'])
    );
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
