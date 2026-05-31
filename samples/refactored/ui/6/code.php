<?php
// app/View/EmptyStateCard.php
namespace App\View;

final class EmptyStateCard
{
    /**
     * @param array{img:string,title:string,body:string,cta:string,
     *              href:string,region:string} $opts
     */
    public static function render(array $opts): string
    {
        $img    = htmlspecialchars($opts['img']);
        $title  = htmlspecialchars($opts['title']);
        $body   = htmlspecialchars($opts['body']);
        $cta    = htmlspecialchars($opts['cta']);
        $href   = htmlspecialchars($opts['href']);
        $region = htmlspecialchars($opts['region']);

        ob_start();
        ?>
        <section class="empty-state card" role="region" aria-label="<?= $region ?>">
            <div class="empty-state-inner">
                <img src="<?= $img ?>" alt="" aria-hidden="true" width="120" height="120">
                <h3 class="empty-state-title"><?= $title ?></h3>
                <p class="empty-state-body"><?= $body ?></p>
                <a href="<?= $href ?>" class="btn btn-primary empty-state-cta">
                    <?= $cta ?>
                </a>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}

// Call sites collapse to a single array literal per page, e.g.:
// EmptyStateCard::render([
//     'img'    => '/static/img/empty-orders.svg',
//     'title'  => 'No orders yet',
//     'body'   => "You haven't placed any orders. When you do, they will show up here.",
//     'cta'    => 'Browse the shop',
//     'href'   => '/products',
//     'region' => 'Empty orders',
// ]);
