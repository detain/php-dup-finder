<?php
// app/Modules/Orders/Templates/CancelOrderModal.php
namespace App\Modules\Orders\Templates;

final class CancelOrderModal
{
    public function render(int $orderId, string $orderNumber): string
    {
        $csrf       = htmlspecialchars($_SESSION['csrf'] ?? '');
        $orderIdEsc = (int) $orderId;
        $orderEsc   = htmlspecialchars($orderNumber);

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
                            Cancel order
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="confirmModalBody">
                        <p>You are about to cancel order <strong><?= $orderEsc ?></strong>.</p>
                        <p class="text-muted small">This action cannot be undone. Press Esc to cancel.</p>
                    </div>
                    <div class="modal-footer">
                        <form method="post" action="/admin/orders/<?= $orderIdEsc ?>/cancel" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fa fa-ban" aria-hidden="true"></i> Cancel order
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
