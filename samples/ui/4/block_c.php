<?php
// app/Modules/Billing/Templates/RefundInvoiceModal.php
namespace App\Modules\Billing\Templates;

final class RefundInvoiceModal
{
    public function render(int $invoiceId, string $invoiceNumber): string
    {
        $csrf         = htmlspecialchars($_SESSION['csrf'] ?? '');
        $invoiceIdEsc = (int) $invoiceId;
        $invoiceEsc   = htmlspecialchars($invoiceNumber);

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
                            Refund invoice
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="confirmModalBody">
                        <p>You are about to refund invoice <strong><?= $invoiceEsc ?></strong>.</p>
                        <p class="text-muted small">This action cannot be undone. Press Esc to cancel.</p>
                    </div>
                    <div class="modal-footer">
                        <form method="post" action="/admin/billing/<?= $invoiceIdEsc ?>/refund" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fa fa-undo" aria-hidden="true"></i> Refund invoice
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
