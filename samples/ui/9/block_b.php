<?php
// app/Admin/Categories/Views/category_row.php
namespace App\Admin\Categories\Views;

final class CategoryRowView
{
    /**
     * @param array{id:int,name:string,parent:string,count:int} $cat
     */
    public function render(array $cat, bool $editing): string
    {
        $csrf   = htmlspecialchars($_SESSION['csrf'] ?? '');
        $id     = (int) $cat['id'];
        $name   = htmlspecialchars($cat['name']);
        $parent = htmlspecialchars($cat['parent']);
        $count  = (int) $cat['count'];

        ob_start();
        if ($editing): ?>
            <tr class="grid-row is-editing" data-id="<?= $id ?>">
                <td><?= $id ?></td>
                <td>
                    <form method="post" action="/admin/categories/<?= $id ?>/rename" class="inline-edit">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="text" name="name" value="<?= $name ?>"
                               class="form-control inline-input"
                               autofocus required aria-label="Category name">
                        <button type="submit" class="btn btn-success btn-sm">Save</button>
                        <a href="?cancel=1" class="btn btn-link btn-sm" aria-label="Cancel edit">Cancel</a>
                        <small class="text-muted ml-1">Esc to cancel</small>
                    </form>
                </td>
                <td><?= $parent ?></td>
                <td><?= $count ?></td>
            </tr>
        <?php else: ?>
            <tr class="grid-row" data-id="<?= $id ?>">
                <td><?= $id ?></td>
                <td><?= $name ?></td>
                <td><?= $parent ?></td>
                <td><?= $count ?></td>
            </tr>
        <?php endif;
        return (string) ob_get_clean();
    }
}
