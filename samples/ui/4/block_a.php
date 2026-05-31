<?php
// app/Modules/Users/Templates/DeleteUserModal.php
namespace App\Modules\Users\Templates;

final class DeleteUserModal
{
    public function render(int $userId, string $username): string
    {
        $csrf      = htmlspecialchars($_SESSION['csrf'] ?? '');
        $userIdEsc = (int) $userId;
        $userEsc   = htmlspecialchars($username);

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
                            Delete user
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="confirmModalBody">
                        <p>You are about to permanently delete <strong><?= $userEsc ?></strong>.</p>
                        <p class="text-muted small">This action cannot be undone. Press Esc to cancel.</p>
                    </div>
                    <div class="modal-footer">
                        <form method="post" action="/admin/users/<?= $userIdEsc ?>/delete" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fa fa-trash" aria-hidden="true"></i> Delete user
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
