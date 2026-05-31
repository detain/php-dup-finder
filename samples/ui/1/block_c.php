<?php
// app/Controllers/VendorProfileController.php
namespace App\Controllers;

final class VendorProfileController
{
    public function editAddress(): string
    {
        $errors = $_SESSION['vendor_errors'] ?? [];
        $vendor = [
            'street'  => $_POST['street']  ?? '12 Industrial Way',
            'city'    => $_POST['city']    ?? 'Newark',
            'state'   => $_POST['state']   ?? 'NJ',
            'zip'     => $_POST['zip']     ?? '07102',
            'country' => $_POST['country'] ?? 'US',
        ];

        ob_start();
        ?>
        <form method="post" action="/vendor/address" class="card p-4">
            <h3>Vendor Address</h3>
            <fieldset class="address-fieldset">
                <div class="form-row">
                    <label for="v_street">Street</label>
                    <input id="v_street" name="street" type="text"
                           value="<?= htmlspecialchars($vendor['street']) ?>"
                           aria-invalid="<?= isset($errors['street']) ? 'true' : 'false' ?>">
                    <?php if (isset($errors['street'])): ?>
                        <span class="err"><?= htmlspecialchars($errors['street']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-row">
                    <label for="v_city">City</label>
                    <input id="v_city" name="city" type="text"
                           value="<?= htmlspecialchars($vendor['city']) ?>"
                           aria-invalid="<?= isset($errors['city']) ? 'true' : 'false' ?>">
                    <?php if (isset($errors['city'])): ?>
                        <span class="err"><?= htmlspecialchars($errors['city']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-row split">
                    <label for="v_state">State</label>
                    <input id="v_state" name="state" type="text" maxlength="2"
                           value="<?= htmlspecialchars($vendor['state']) ?>">
                    <label for="v_zip">ZIP</label>
                    <input id="v_zip" name="zip" type="text"
                           value="<?= htmlspecialchars($vendor['zip']) ?>">
                </div>
                <div class="form-row">
                    <label for="v_country">Country</label>
                    <input id="v_country" name="country" type="text"
                           value="<?= htmlspecialchars($vendor['country']) ?>">
                </div>
            </fieldset>
            <button type="submit" class="btn-primary">Save Vendor Address</button>
        </form>
        <?php
        return (string) ob_get_clean();
    }
}
