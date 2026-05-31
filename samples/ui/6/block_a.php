<?php
// app/Templates/orders_list.php
namespace App\Templates;

final class OrdersListTemplate
{
    /** @param array<int,array<string,mixed>> $orders */
    public function render(array $orders): string
    {
        if (count($orders) === 0) {
            $imgUrl = '/static/img/empty-orders.svg';
            $title  = 'No orders yet';
            $body   = "You haven't placed any orders. When you do, they will show up here.";
            $cta    = 'Browse the shop';
            $href   = '/products';

            ob_start();
            ?>
            <section class="empty-state card" role="region" aria-label="Empty orders">
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

        // ... populated list rendering omitted for brevity ...
        ob_start();
        echo '<ul class="orders-list">';
        foreach ($orders as $o) {
            printf('<li>#%d &mdash; %s</li>', $o['id'], htmlspecialchars($o['title']));
        }
        echo '</ul>';
        return (string) ob_get_clean();
    }
}
