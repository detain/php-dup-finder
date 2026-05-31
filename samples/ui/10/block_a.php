<?php
// app/Print/Views/InvoiceReceipt.php
namespace App\Print\Views;

final class InvoiceReceipt
{
    /** @param array{number:string,date:string,bill_to:string,subtotal:float,tax:float,total:float,barcode:string} $inv */
    public function render(array $inv): string
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
                <tr><th>Invoice #</th><td><?= htmlspecialchars($inv['number']) ?></td></tr>
                <tr><th>Date</th>     <td><?= htmlspecialchars($inv['date']) ?></td></tr>
                <tr><th>Bill to</th>  <td><?= nl2br(htmlspecialchars($inv['bill_to'])) ?></td></tr>
            </table>

            <footer class="receipt-totals">
                <table>
                    <tr><th>Subtotal</th><td><?= $fmt($inv['subtotal']) ?></td></tr>
                    <tr><th>Tax</th>     <td><?= $fmt($inv['tax']) ?></td></tr>
                    <tr class="grand"><th>Total</th><td><?= $fmt($inv['total']) ?></td></tr>
                </table>
                <div class="barcode">
                    <img src="/barcode/<?= urlencode($inv['barcode']) ?>.png" alt="">
                    <small><?= htmlspecialchars($inv['barcode']) ?></small>
                </div>
            </footer>

            <style>@media print { .receipt-print { margin: 0; padding: 0.5in; } }</style>
        </article>
        <?php
        return (string) ob_get_clean();
    }
}
