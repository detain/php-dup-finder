<?php
// app/Templates/documents_list.php
namespace App\Templates;

final class DocumentsListTemplate
{
    /** @param array<int,array<string,mixed>> $docs */
    public function render(array $docs): string
    {
        if (count($docs) === 0) {
            $imgUrl = '/static/img/empty-docs.svg';
            $title  = 'No documents';
            $body   = "Your folder is empty. Upload a file or generate one from a template to get started.";
            $cta    = 'Upload a document';
            $href   = '/documents/upload';

            ob_start();
            ?>
            <section class="empty-state card" role="region" aria-label="Empty documents">
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
        echo '<ul class="documents-list">';
        foreach ($docs as $d) {
            printf('<li>%s (%s)</li>', htmlspecialchars($d['name']), htmlspecialchars($d['size']));
        }
        echo '</ul>';
        return (string) ob_get_clean();
    }
}
