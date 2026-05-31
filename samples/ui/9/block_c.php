<?php
// app/Admin/Warehouses/Views/warehouse_row.php
namespace App\Admin\Warehouses\Views;

final class WarehouseRowView
{
    /**
     * @param array{id:int,name:string,region:string,stock:int} $wh
     */
    public function render(array $wh, bool $editing): string
    {
        $csrf   = htmlspecialchars($_SESSION['csrf'] ?? '');
        $id     = (int) $wh['id'];
        $name   = htmlspecialchars($wh['name']);
        $region = htmlspecialchars($wh['region']);
        $stock  = (int) $wh['stock'];

        ob_start();
        if ($editing): ?>
            <tr class="grid-row is-editing" data-id="<?= $id ?>">
                <td><?= $id ?></td>
                <td>
                    <form method="post" action="/admin/warehouses/<?= $id ?>/rename" class="inline-edit">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="text" name="name" value="<?= $name ?>"
                               class="form-control inline-input"
                               autofocus required aria-label="Warehouse name">
                        <button type="submit" class="btn btn-success btn-sm">Save</button>
                        <a href="?cancel=1" class="btn btn-link btn-sm" aria-label="Cancel edit">Cancel</a>
                        <small class="text-muted ml-1">Esc to cancel</small>
                    </form>
                </td>
                <td><?= $region ?></td>
                <td><?= $stock ?></td>
            </tr>
        <?php else: ?>
            <tr class="grid-row" data-id="<?= $id ?>">
                <td><?= $id ?></td>
                <td><?= $name ?></td>
                <td><?= $region ?></td>
                <td><?= $stock ?></td>
            </tr>
        <?php endif;
        return (string) ob_get_clean();
    }
}
