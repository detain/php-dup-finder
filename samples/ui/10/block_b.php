<?php
// app/Print/Views/PackingSlipReceipt.php
namespace App\Print\Views;

final class PackingSlipReceipt
{
    /** @param array{number:string,date:string,ship_to:string,items_count:int,weight:float,total:float,barcode:string} $slip */
    public function render(array $slip): string
    {
        $companyName = 'Acme Widgets, Inc.';
        $companyAddr = '123 Main St, Springfield, IL 62701';
        $logoUrl     = '/static/img/logo-print.png';
        $fmt         = static fn(float $n): string => number_format($n, 2);

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
                <tr><th>Packing #</th><td><?= htmlspecialchars($slip['number']) ?></td></tr>
                <tr><th>Date</th>     <td><?= htmlspecialchars($slip['date']) ?></td></tr>
                <tr><th>Ship to</th>  <td><?= nl2br(htmlspecialchars($slip['ship_to'])) ?></td></tr>
            </table>

            <footer class="receipt-totals">
                <table>
                    <tr><th>Items</th>  <td><?= (int) $slip['items_count'] ?></td></tr>
                    <tr><th>Weight</th> <td><?= $fmt($slip['weight']) ?> kg</td></tr>
                    <tr class="grand"><th>Total qty</th><td><?= $fmt($slip['total']) ?></td></tr>
                </table>
                <div class="barcode">
                    <img src="/barcode/<?= urlencode($slip['barcode']) ?>.png" alt="">
                    <small><?= htmlspecialchars($slip['barcode']) ?></small>
                </div>
            </footer>

            <style>@media print { .receipt-print { margin: 0; padding: 0.5in; } }</style>
        </article>
        <?php
        return (string) ob_get_clean();
    }
}
