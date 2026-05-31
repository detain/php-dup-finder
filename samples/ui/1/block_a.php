<?php
// app/Controllers/BillingController.php
namespace App\Controllers;

final class BillingController
{
    public function edit(): string
    {
        $errors  = $_SESSION['billing_errors'] ?? [];
        $billing = [
            'street'  => $_POST['street']  ?? 'P.O. Box 7',
            'city'    => $_POST['city']    ?? 'Austin',
            'state'   => $_POST['state']   ?? 'TX',
            'zip'     => $_POST['zip']     ?? '78701',
            'country' => $_POST['country'] ?? 'US',
        ];

        ob_start();
        ?>
        <form method="post" action="/billing/save" class="card p-4">
            <h3>Billing Address</h3>
            <fieldset class="address-fieldset">
                <div class="form-row">
                    <label for="b_street">Street</label>
                    <input id="b_street" name="street" type="text"
                           value="<?= htmlspecialchars($billing['street']) ?>"
                           aria-invalid="<?= isset($errors['street']) ? 'true' : 'false' ?>">
                    <?php if (isset($errors['street'])): ?>
                        <span class="err"><?= htmlspecialchars($errors['street']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-row">
                    <label for="b_city">City</label>
                    <input id="b_city" name="city" type="text"
                           value="<?= htmlspecialchars($billing['city']) ?>"
                           aria-invalid="<?= isset($errors['city']) ? 'true' : 'false' ?>">
                    <?php if (isset($errors['city'])): ?>
                        <span class="err"><?= htmlspecialchars($errors['city']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-row split">
                    <label for="b_state">State</label>
                    <input id="b_state" name="state" type="text" maxlength="2"
                           value="<?= htmlspecialchars($billing['state']) ?>">
                    <label for="b_zip">ZIP</label>
                    <input id="b_zip" name="zip" type="text"
                           value="<?= htmlspecialchars($billing['zip']) ?>">
                </div>
                <div class="form-row">
                    <label for="b_country">Country</label>
                    <input id="b_country" name="country" type="text"
                           value="<?= htmlspecialchars($billing['country']) ?>">
                </div>
            </fieldset>
            <button type="submit" class="btn-primary">Save Billing</button>
        </form>
        <?php
        return (string) ob_get_clean();
    }
}
