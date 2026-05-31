<?php
// app/View/InlineEditableRow.php
namespace App\View;

final class InlineEditableRow
{
    /**
     * Render a 4-column admin grid row with inline-edit on the second cell.
     *
     * @param int             $id
     * @param string          $editableValue   value of the editable column
     * @param array{0:string,1:string}|array{} $tailCells two extra display cells (already escaped)
     * @param string          $renameAction    POST action URL
     * @param string          $resourceLabel   aria-label for the input ("Tag name", etc.)
     * @param bool            $editing
     */
    public static function render(
        int $id,
        string $editableValue,
        array $tailCells,
        string $renameAction,
        string $resourceLabel,
        bool $editing
    ): string {
        $csrf       = htmlspecialchars($_SESSION['csrf'] ?? '');
        $name       = htmlspecialchars($editableValue);
        $actionUrl  = htmlspecialchars($renameAction);
        $aria       = htmlspecialchars($resourceLabel);
        [$cellA, $cellB] = $tailCells + ['', ''];

        ob_start();
        if ($editing): ?>
            <tr class="grid-row is-editing" data-id="<?= $id ?>">
                <td><?= $id ?></td>
                <td>
                    <form method="post" action="<?= $actionUrl ?>" class="inline-edit">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="text" name="name" value="<?= $name ?>"
                               class="form-control inline-input"
                               autofocus required aria-label="<?= $aria ?>">
                        <button type="submit" class="btn btn-success btn-sm">Save</button>
                        <a href="?cancel=1" class="btn btn-link btn-sm" aria-label="Cancel edit">Cancel</a>
                        <small class="text-muted ml-1">Esc to cancel</small>
                    </form>
                </td>
                <td><?= $cellA ?></td>
                <td><?= $cellB ?></td>
            </tr>
        <?php else: ?>
            <tr class="grid-row" data-id="<?= $id ?>">
                <td><?= $id ?></td>
                <td><?= $name ?></td>
                <td><?= $cellA ?></td>
                <td><?= $cellB ?></td>
            </tr>
        <?php endif;
        return (string) ob_get_clean();
    }
}
