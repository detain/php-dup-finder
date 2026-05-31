<?php
// app/Print/Views/RefundSlipReceipt.php
namespace App\Print\Views;

final class RefundSlipReceipt
{
    /** @param array{number:string,date:string,refund_to:string,goods_value:float,fee:float,total:float,barcode:string} $rf */
    public function render(array $rf): string
    {
        $companyName = 'Acme Widgets, Inc.';
        $companyAddr = '123 Main St, Springfield, IL 62701';
        $logoUrl     = '/static/img/logo-print.png';
        $fmt         = static fn(float $n): string => '$' . number_format($n, 2);

        ob_start();
        ?>
        <article class="receipt receipt-print">
            <header class="receipt-header">
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($companyName) ?>" class="receipt-logo">
                <div class="company-block">
                    <h1><?= htmlspecialchars($companyName) ?></h1>
                    <p><?= htmlspecialchars($companyAddr) ?></p>
                </div>
            </header>

            <table class="receipt-meta">
                <tr><th>Refund #</th>  <td><?= htmlspecialchars($rf['number']) ?></td></tr>
                <tr><th>Date</th>      <td><?= htmlspecialchars($rf['date']) ?></td></tr>
                <tr><th>Refund to</th> <td><?= nl2br(htmlspecialchars($rf['refund_to'])) ?></td></tr>
            </table>

            <footer class="receipt-totals">
                <table>
                    <tr><th>Goods value</th><td><?= $fmt($rf['goods_value']) ?></td></tr>
                    <tr><th>Restocking fee</th><td>-<?= $fmt($rf['fee']) ?></td></tr>
                    <tr class="grand"><th>Refund total</th><td><?= $fmt($rf['total']) ?></td></tr>
                </table>
                <div class="barcode">
                    <img src="/barcode/<?= urlencode($rf['barcode']) ?>.png" alt="">
                    <small><?= htmlspecialchars($rf['barcode']) ?></small>
                </div>
            </footer>

            <style>@media print { .receipt-print { margin: 0; padding: 0.5in; } }</style>
        </article>
        <?php
        return (string) ob_get_clean();
    }
}
