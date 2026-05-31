<?php
// app/Settings/Billing/BillingSettingsPage.php
namespace App\Settings\Billing;

final class BillingSettingsPage
{
    public function render(): string
    {
        $tabs = [
            'plan'     => 'Plan',
            'payment'  => 'Payment Method',
            'invoices' => 'Invoices',
            'tax'      => 'Tax Info',
        ];
        $active = $_GET['tab'] ?? 'plan';
        if (!isset($tabs[$active])) {
            $active = 'plan';
        }

        ob_start();
        ?>
        <div class="settings-page">
            <header class="settings-header">
                <a href="/billing" class="btn-back">&larr; Back to billing</a>
                <h2>Billing Settings</h2>
            </header>
            <ul class="nav nav-tabs" role="tablist">
                <?php foreach ($tabs as $slug => $label):
                    $cls = $slug === $active ? 'nav-link active' : 'nav-link';
                ?>
                    <li class="nav-item" role="presentation">
                        <a href="?tab=<?= urlencode($slug) ?>"
                           class="<?= $cls ?>"
                           role="tab"
                           aria-selected="<?= $slug === $active ? 'true' : 'false' ?>">
                            <?= htmlspecialchars($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <section class="tab-content" role="tabpanel">
                <?php
                switch ($active) {
                    case 'plan':     echo '<p>Plan selector...</p>'; break;
                    case 'payment':  echo '<p>Payment method form...</p>'; break;
                    case 'invoices': echo '<p>Invoice history...</p>'; break;
                    case 'tax':      echo '<p>Tax information...</p>'; break;
                }
                ?>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
