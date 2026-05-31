<?php
// app/Controllers/ShippingController.php
namespace App\Controllers;

final class ShippingController
{
    public function edit(): string
    {
        $errors  = $_SESSION['ship_errors'] ?? [];
        $ship    = [
            'street'  => $_POST['street']  ?? '500 Pine St',
            'city'    => $_POST['city']    ?? 'Seattle',
            'state'   => $_POST['state']   ?? 'WA',
            'zip'     => $_POST['zip']     ?? '98101',
            'country' => $_POST['country'] ?? 'US',
        ];

        ob_start();
        ?>
        <form method="post" action="/shipping/save" class="card p-4">
            <h3>Shipping Address</h3>
            <fieldset class="address-fieldset">
                <div class="form-row">
                    <label for="s_street">Street</label>
                    <input id="s_street" name="street" type="text"
                           value="<?= htmlspecialchars($ship['street']) ?>"
                           aria-invalid="<?= isset($errors['street']) ? 'true' : 'false' ?>">
                    <?php if (isset($errors['street'])): ?>
                        <span class="err"><?= htmlspecialchars($errors['street']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-row">
                    <label for="s_city">City</label>
                    <input id="s_city" name="city" type="text"
                           value="<?= htmlspecialchars($ship['city']) ?>"
                           aria-invalid="<?= isset($errors['city']) ? 'true' : 'false' ?>">
                    <?php if (isset($errors['city'])): ?>
                        <span class="err"><?= htmlspecialchars($errors['city']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-row split">
                    <label for="s_state">State</label>
                    <input id="s_state" name="state" type="text" maxlength="2"
                           value="<?= htmlspecialchars($ship['state']) ?>">
                    <label for="s_zip">ZIP</label>
                    <input id="s_zip" name="zip" type="text"
                           value="<?= htmlspecialchars($ship['zip']) ?>">
                </div>
                <div class="form-row">
                    <label for="s_country">Country</label>
                    <input id="s_country" name="country" type="text"
                           value="<?= htmlspecialchars($ship['country']) ?>">
                </div>
            </fieldset>
            <button type="submit" class="btn-primary">Save Shipping</button>
        </form>
        <?php
        return (string) ob_get_clean();
    }
}
