<?php
// app/ApiKeys/Views/api_keys_toast.php
namespace App\ApiKeys\Views;

final class ApiKeysToast
{
    public function render(): string
    {
        $messages = [
            'success' => $_SESSION['apikey_success'] ?? null,
            'warning' => $_SESSION['apikey_warning'] ?? null,
            'error'   => $_SESSION['apikey_error']   ?? null,
        ];
        unset($_SESSION['apikey_success'], $_SESSION['apikey_warning'], $_SESSION['apikey_error']);

        $variantMap = [
            'success' => ['cls' => 'toast-success', 'icon' => 'check-circle', 'live' => 'polite'],
            'warning' => ['cls' => 'toast-warning', 'icon' => 'exclamation',  'live' => 'polite'],
            'error'   => ['cls' => 'toast-error',   'icon' => 'times-circle', 'live' => 'assertive'],
        ];

        ob_start();
        foreach ($messages as $variant => $text) {
            if ($text === null) {
                continue;
            }
            $cfg = $variantMap[$variant];
            ?>
            <div class="toast <?= $cfg['cls'] ?>" role="alert"
                 aria-live="<?= $cfg['live'] ?>" aria-atomic="true">
                <span class="toast-icon" aria-hidden="true">
                    <svg width="16" height="16"><use xlink:href="#icon-<?= $cfg['icon'] ?>"/></svg>
                </span>
                <span class="toast-text"><?= htmlspecialchars((string) $text) ?></span>
                <button type="button" class="toast-close" aria-label="Dismiss" data-dismiss="toast">
                    &times;
                </button>
            </div>
            <?php
        }
        return (string) ob_get_clean();
    }
}
