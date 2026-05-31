<?php
// app/Settings/Integrations/IntegrationsSettingsPage.php
namespace App\Settings\Integrations;

final class IntegrationsSettingsPage
{
    public function render(): string
    {
        $tabs = [
            'slack'   => 'Slack',
            'github'  => 'GitHub',
            'jira'    => 'Jira',
            'webhooks'=> 'Webhooks',
        ];
        $active = $_GET['tab'] ?? 'slack';
        if (!isset($tabs[$active])) {
            $active = 'slack';
        }

        ob_start();
        ?>
        <div class="settings-page">
            <header class="settings-header">
                <a href="/integrations" class="btn-back">&larr; Back to integrations</a>
                <h2>Integrations</h2>
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
                    case 'slack':    echo '<p>Slack connector...</p>'; break;
                    case 'github':   echo '<p>GitHub app install...</p>'; break;
                    case 'jira':     echo '<p>Jira credentials...</p>'; break;
                    case 'webhooks': echo '<p>Webhook list...</p>'; break;
                }
                ?>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
