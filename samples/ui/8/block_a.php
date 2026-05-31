<?php
// app/Settings/Account/AccountSettingsPage.php
namespace App\Settings\Account;

final class AccountSettingsPage
{
    public function render(): string
    {
        $tabs = [
            'profile'   => 'Profile',
            'password'  => 'Password',
            'sessions'  => 'Active Sessions',
            'delete'    => 'Close Account',
        ];
        $active = $_GET['tab'] ?? 'profile';
        if (!isset($tabs[$active])) {
            $active = 'profile';
        }

        ob_start();
        ?>
        <div class="settings-page">
            <header class="settings-header">
                <a href="/account" class="btn-back">&larr; Back to account</a>
                <h2>Account Settings</h2>
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
                    case 'profile':  echo '<p>Profile form...</p>'; break;
                    case 'password': echo '<p>Password change form...</p>'; break;
                    case 'sessions': echo '<p>Sessions list...</p>'; break;
                    case 'delete':   echo '<p>Account closure form...</p>'; break;
                }
                ?>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
