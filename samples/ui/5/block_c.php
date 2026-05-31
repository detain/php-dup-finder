<?php
// app/Kyc/Step2Identity.php
namespace App\Kyc;

final class Step2Identity
{
    public function render(array $session): string
    {
        $steps   = ['Welcome', 'Identity', 'Documents', 'Verify'];
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
                <h2>KYC — Identity</h2>
                <?= $progress ?>
            </header>
            <form method="post" action="/kyc/step/3" class="wizard-body">
                <div class="form-row">
                    <label for="legal_name">Legal name</label>
                    <input id="legal_name" name="legal_name" type="text"
                           value="<?= htmlspecialchars($session['legal_name'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <label for="dob">Date of birth</label>
                    <input id="dob" name="dob" type="date"
                           value="<?= htmlspecialchars($session['dob'] ?? '') ?>">
                </div>
                <footer class="wizard-footer">
                    <a href="/kyc/step/<?= $current - 1 ?>"
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
