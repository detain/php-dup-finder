<?php
// app/View/TabbedSettingsPage.php
namespace App\View;

final class TabbedSettingsPage
{
    /**
     * @param array<string,string>          $tabs    slug => label
     * @param array<string,callable():string> $panels  slug => callable returning html
     */
    public static function render(
        string $heading,
        string $backUrl,
        string $backLabel,
        array $tabs,
        array $panels,
        ?string $requestedTab
    ): string {
        $slugs  = array_keys($tabs);
        $active = $requestedTab !== null && isset($tabs[$requestedTab])
                ? $requestedTab
                : ($slugs[0] ?? '');

        ob_start();
        ?>
        <div class="settings-page">
            <header class="settings-header">
                <a href="<?= htmlspecialchars($backUrl) ?>" class="btn-back">
                    &larr; <?= htmlspecialchars($backLabel) ?>
                </a>
                <h2><?= htmlspecialchars($heading) ?></h2>
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
                <?= isset($panels[$active]) ? $panels[$active]() : '' ?>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
