<?php
declare(strict_types=1);

// tests/Fixtures/micro-fixture/scripts_2.php — three near-identical
// top-level scripts differing only in the target table name
// (improve_logic.md Appendix A / doc 1.3 script blind-spot pattern).

$rows = [];
$db = get_module_db('billing');
$result = $db->query('select id, amount from payments where due_date < now()');
while ($db->next_record()) {
    $rows[] = ['id' => $db->f('id'), 'amount' => (float) $db->f('amount')];
}
foreach ($rows as $row) {
    $db->query('update payments set flagged = 1 where id = ' . (int) $row['id']);
}
echo json_encode(['count' => count($rows)]), "\n";
