<?php
// app/View/PrintReceipt.php
namespace App\View;

final class PrintReceipt
{
    private const COMPANY_NAME = 'Acme Widgets, Inc.';
    private const COMPANY_ADDR = '123 Main St, Springfield, IL 62701';
    private const LOGO_URL     = '/static/img/logo-print.png';

    /**
     * @param array{label:string,value:string} $numberRow
     * @param string                           $date
     * @param array{label:string,value:string} $partyRow recipient row (Bill to / Ship to / Refund to)
     * @param array<int,array{label:string,value:string,grand?:bool}> $totalsRows
     * @param string                           $barcode
     */
    public static function render(
        array $numberRow,
        string $date,
        array $partyRow,
        array $totalsRows,
        string $barcode
    ): string {
        ob_start();
        ?>
        <article class="receipt receipt-print">
            <header class="receipt-header">
                <img src="<?= htmlspecialchars(self::LOGO_URL) ?>"
                     alt="<?= htmlspecialchars(self::COMPANY_NAME) ?>" class="receipt-logo">
                <div class="company-block">
                    <h1><?= htmlspecialchars(self::COMPANY_NAME) ?></h1>
                    <p><?= htmlspecialchars(self::COMPANY_ADDR) ?></p>
                </div>
            </header>

            <table class="receipt-meta">
                <tr><th><?= htmlspecialchars($numberRow['label']) ?></th>
                    <td><?= htmlspecialchars($numberRow['value']) ?></td></tr>
                <tr><th>Date</th><td><?= htmlspecialchars($date) ?></td></tr>
                <tr><th><?= htmlspecialchars($partyRow['label']) ?></th>
                    <td><?= nl2br(htmlspecialchars($partyRow['value'])) ?></td></tr>
            </table>

            <footer class="receipt-totals">
                <table>
                    <?php foreach ($totalsRows as $row):
                        $cls = !empty($row['grand']) ? ' class="grand"' : '';
                    ?>
                        <tr<?= $cls ?>><th><?= htmlspecialchars($row['label']) ?></th>
                            <td><?= htmlspecialchars($row['value']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
                <div class="barcode">
                    <img src="/barcode/<?= urlencode($barcode) ?>.png" alt="">
                    <small><?= htmlspecialchars($barcode) ?></small>
                </div>
            </footer>

            <style>@media print { .receipt-print { margin: 0; padding: 0.5in; } }</style>
        </article>
        <?php
        return (string) ob_get_clean();
    }
}
