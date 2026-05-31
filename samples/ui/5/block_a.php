<?php
// app/Checkout/Step2Shipping.php
namespace App\Checkout;

final class Step2Shipping
{
    public function render(array $session): string
    {
        $steps   = ['Cart', 'Shipping', 'Payment', 'Review'];
        $current = 2;
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

        ob_start();
        ?>
        <section class="wizard">
            <header class="wizard-header">
                <h2>Checkout — Shipping</h2>
                <?= $progress ?>
            </header>
            <form method="post" action="/checkout/step/3" class="wizard-body">
                <div class="form-row">
                    <label for="ship_addr">Ship to</label>
                    <textarea id="ship_addr" name="ship_addr" rows="3"><?= htmlspecialchars($session['ship_addr'] ?? '') ?></textarea>
                </div>
                <div class="form-row">
                    <label for="ship_method">Method</label>
                    <select id="ship_method" name="ship_method">
                        <option value="std">Standard (3-5 days)</option>
                        <option value="exp">Express (1-2 days)</option>
                    </select>
                </div>
                <footer class="wizard-footer">
                    <a href="/checkout/step/<?= $current - 1 ?>"
                       class="btn-link <?= $isFirst ? 'is-disabled' : '' ?>">&laquo; Back</a>
                    <button type="submit" class="btn-primary">
                        <?= $isLast ? 'Finish' : 'Continue' ?> &raquo;
                    </button>
                </footer>
            </form>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
