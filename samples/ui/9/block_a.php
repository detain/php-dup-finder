<?php
// app/Admin/Tags/Views/tag_row.php
namespace App\Admin\Tags\Views;

final class TagRowView
{
    /**
     * @param array{id:int,name:string,slug:string,used:int} $tag
     */
    public function render(array $tag, bool $editing): string
    {
        $csrf = htmlspecialchars($_SESSION['csrf'] ?? '');
        $id   = (int) $tag['id'];
        $name = htmlspecialchars($tag['name']);
        $slug = htmlspecialchars($tag['slug']);
        $used = (int) $tag['used'];

        ob_start();
        if ($editing): ?>
            <tr class="grid-row is-editing" data-id="<?= $id ?>">
                <td><?= $id ?></td>
                <td>
                    <form method="post" action="/admin/tags/<?= $id ?>/rename" class="inline-edit">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="text" name="name" value="<?= $name ?>"
                               class="form-control inline-input"
                               autofocus required aria-label="Tag name">
                        <button type="submit" class="btn btn-success btn-sm">Save</button>
                        <a href="?cancel=1" class="btn btn-link btn-sm" aria-label="Cancel edit">Cancel</a>
                        <small class="text-muted ml-1">Esc to cancel</small>
                    </form>
                </td>
                <td><?= $slug ?></td>
                <td><?= $used ?></td>
            </tr>
        <?php else: ?>
            <tr class="grid-row" data-id="<?= $id ?>">
                <td><?= $id ?></td>
                <td><?= $name ?></td>
                <td><?= $slug ?></td>
                <td><?= $used ?></td>
            </tr>
        <?php endif;
        return (string) ob_get_clean();
    }
}
