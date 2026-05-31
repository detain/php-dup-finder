<?php
// app/Templates/notifications_list.php
namespace App\Templates;

final class NotificationsListTemplate
{
    /** @param array<int,array<string,mixed>> $items */
    public function render(array $items): string
    {
        if (count($items) === 0) {
            $imgUrl = '/static/img/empty-bell.svg';
            $title  = 'No notifications';
            $body   = "You're all caught up. New alerts will appear here as they arrive.";
            $cta    = 'Manage preferences';
            $href   = '/settings/notifications';

            ob_start();
            ?>
            <section class="empty-state card" role="region" aria-label="Empty notifications">
                <div class="empty-state-inner">
                    <img src="<?= htmlspecialchars($imgUrl) ?>" alt="" aria-hidden="true" width="120" height="120">
                    <h3 class="empty-state-title"><?= htmlspecialchars($title) ?></h3>
                    <p class="empty-state-body"><?= htmlspecialchars($body) ?></p>
                    <a href="<?= htmlspecialchars($href) ?>" class="btn btn-primary empty-state-cta">
                        <?= htmlspecialchars($cta) ?>
                    </a>
                </div>
            </section>
            <?php
            return (string) ob_get_clean();
        }

        ob_start();
        echo '<ul class="notifications-list">';
        foreach ($items as $n) {
            printf('<li>%s</li>', htmlspecialchars($n['message']));
        }
        echo '</ul>';
        return (string) ob_get_clean();
    }
}
