<?php
// app/View/WizardShell.php
namespace App\View;

final class WizardShell
{
    /**
     * @param string[] $steps
     */
    public static function render(
        string $title,
        array $steps,
        int $current,
        string $baseUrl,
        string $bodyHtml
    ): string {
        $isFirst = $current === 1;
        $isLast  = $current === count($steps);

        $progress = '<ol class="wizard-progress">';
        foreach ($steps as $i => $label) {
            $stepNum = $i + 1;
            $state   = $stepNum < $current ? 'done'
                    : ($stepNum === $current ? 'active' : 'todo');
            $progress .= sprintf(
                '<li class="step step-%s"><span class="pill">%d</span> %s</li>',
                $state, $stepNum, htmlspecialchars($label)
            );
        }
        $progress .= '</ol>';

        $next     = $current + 1;
        $back     = $current - 1;
        $nextLabel = $isLast ? 'Finish' : 'Continue';
        $backCls   = $isFirst ? 'is-disabled' : '';
        $escTitle  = htmlspecialchars($title);
        $escBase   = htmlspecialchars($baseUrl);

        ob_start();
        ?>
        <section class="wizard">
            <header class="wizard-header">
                <h2><?= $escTitle ?></h2>
                <?= $progress ?>
            </header>
            <form method="post" action="<?= $escBase ?>/step/<?= $next ?>" class="wizard-body">
                <?= $bodyHtml ?>
                <footer class="wizard-footer">
                    <a href="<?= $escBase ?>/step/<?= $back ?>" class="btn-link <?= $backCls ?>">&laquo; Back</a>
                    <button type="submit" class="btn-primary"><?= $nextLabel ?> &raquo;</button>
                </footer>
            </form>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
