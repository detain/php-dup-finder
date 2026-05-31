<?php
// app/View/ConfirmModal.php
namespace App\View;

final class ConfirmModal
{
    /**
     * @param array{title:string,bodyHtml:string,action:string,
     *              confirmLabel:string,confirmIcon:string} $opts
     */
    public static function render(array $opts): string
    {
        $csrf      = htmlspecialchars($_SESSION['csrf'] ?? '');
        $title     = htmlspecialchars($opts['title']);
        $body      = $opts['bodyHtml']; // caller responsible for escaping
        $action    = htmlspecialchars($opts['action']);
        $btnLabel  = htmlspecialchars($opts['confirmLabel']);
        $btnIcon   = htmlspecialchars($opts['confirmIcon']);

        ob_start();
        ?>
        <div class="modal fade" id="confirmModal" tabindex="-1"
             role="dialog" aria-labelledby="confirmModalTitle"
             aria-describedby="confirmModalBody" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="confirmModalTitle">
                            <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                            <?= $title ?>
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="confirmModalBody">
                        <?= $body ?>
                        <p class="text-muted small">This action cannot be undone. Press Esc to cancel.</p>
                    </div>
                    <div class="modal-footer">
                        <form method="post" action="<?= $action ?>" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fa <?= $btnIcon ?>" aria-hidden="true"></i> <?= $btnLabel ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
