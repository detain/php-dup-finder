<?php
// app/Onboarding/Step2Profile.php
namespace App\Onboarding;

final class Step2Profile
{
    public function render(array $session): string
    {
        $steps   = ['Account', 'Profile', 'Preferences', 'Confirm'];
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
                <h2>Onboarding — Profile</h2>
                <?= $progress ?>
            </header>
            <form method="post" action="/onboarding/step/3" class="wizard-body">
                <div class="form-row">
                    <label for="display_name">Display name</label>
                    <input id="display_name" name="display_name" type="text"
                           value="<?= htmlspecialchars($session['display_name'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <label for="bio">Short bio</label>
                    <textarea id="bio" name="bio" rows="3"><?= htmlspecialchars($session['bio'] ?? '') ?></textarea>
                </div>
                <footer class="wizard-footer">
                    <a href="/onboarding/step/<?= $current - 1 ?>"
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
