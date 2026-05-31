<?php
// admin/shipments.php
require __DIR__ . '/../bootstrap.php';

$page    = max(1, (int) ($_GET['page'] ?? 1));
$sort    = $_GET['sort'] ?? 'shipped_at';
$dir     = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$perPage = 15;

$shipments = $db->fetchAll(
    "SELECT id, carrier, weight, status, shipped_at FROM shipments ORDER BY {$sort} {$dir} LIMIT ?, ?",
    [($page - 1) * $perPage, $perPage]
);
$total = (int) $db->fetchOne("SELECT COUNT(*) FROM shipments");
$pages = (int) ceil($total / $perPage);

echo '<h2>Shipments</h2>';
echo '<table class="data-table"><thead><tr>';
foreach (['id' => 'Tracking', 'carrier' => 'Carrier', 'weight' => 'Weight', 'status' => 'Status', 'shipped_at' => 'Shipped'] as $col => $label) {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow  = $sort === $col ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '';
    printf(
        '<th><a href="?sort=%s&dir=%s&page=1">%s%s</a></th>',
        $col, $newDir, htmlspecialchars($label), $arrow
    );
}
echo '</tr></thead><tbody>';
foreach ($shipments as $row) {
    printf(
        '<tr><td>%d</td><td>%s</td><td>%.1f kg</td><td><span class="badge badge-%s">%s</span></td><td>%s</td></tr>',
        $row['id'],
        htmlspecialchars($row['carrier']),
        (float) $row['weight'],
        htmlspecialchars($row['status']),
        htmlspecialchars(ucfirst($row['status'])),
        htmlspecialchars($row['shipped_at'])
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
