<?php
// app/Auth/Views/auth_toast.php
namespace App\Auth\Views;

final class AuthToast
{
    public function render(): string
    {
        $messages = [
            'success' => $_SESSION['flash_success'] ?? null,
            'warning' => $_SESSION['flash_warning'] ?? null,
            'error'   => $_SESSION['flash_error']   ?? null,
        ];
        unset($_SESSION['flash_success'], $_SESSION['flash_warning'], $_SESSION['flash_error']);

        $variantMap = [
            'success' => ['cls' => 'toast-success', 'icon' => 'check-circle',     'live' => 'polite'],
            'warning' => ['cls' => 'toast-warning', 'icon' => 'exclamation',      'live' => 'polite'],
            'error'   => ['cls' => 'toast-error',   'icon' => 'times-circle',     'live' => 'assertive'],
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
