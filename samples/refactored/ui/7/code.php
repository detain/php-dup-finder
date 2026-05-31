<?php
// app/View/FlashToaster.php
namespace App\View;

final class FlashToaster
{
    private const VARIANT_MAP = [
        'success' => ['cls' => 'toast-success', 'icon' => 'check-circle', 'live' => 'polite'],
        'warning' => ['cls' => 'toast-warning', 'icon' => 'exclamation',  'live' => 'polite'],
        'error'   => ['cls' => 'toast-error',   'icon' => 'times-circle', 'live' => 'assertive'],
    ];

    /**
     * @param array<string,string> $sessionKeys variant => session key
     */
    public static function render(array $sessionKeys): string
    {
        $messages = [];
        foreach ($sessionKeys as $variant => $key) {
            $messages[$variant] = $_SESSION[$key] ?? null;
            unset($_SESSION[$key]);
        }

        ob_start();
        foreach ($messages as $variant => $text) {
            if ($text === null) {
                continue;
            }
            $cfg = self::VARIANT_MAP[$variant];
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

// Call sites collapse to a single map of session keys per module, e.g.:
// FlashToaster::render([
//     'success' => 'flash_success',
//     'warning' => 'flash_warning',
//     'error'   => 'flash_error',
// ]);
