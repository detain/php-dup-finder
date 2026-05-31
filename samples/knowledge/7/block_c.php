<?php

declare(strict_types=1);

namespace App\Admin\Tools;

use App\Repositories\UserRepository;
use App\Templating\TemplateEngine;

final class UserLockoutPanel
{
    public function __construct(
        private UserRepository $users,
        private TemplateEngine $templates,
    ) {}

    public function render(int $userId): string
    {
        $user = $this->users->findOrFail($userId);
        $recentFailures = $this->users->recentFailedLogins($userId, 900); // 15 min window

        $progress = min(count($recentFailures), 5);
        $statusLabel = match (true) {
            $user->lockedUntil !== null && $user->lockedUntil > time() => 'Locked',
            $progress === 4 => 'At risk',
            default => 'OK',
        };

        return $this->templates->render('admin.users.lockout_panel', [
            'user' => $user,
            'failures' => $recentFailures,
            'failures_count' => count($recentFailures),
            'policy_max_attempts' => 5,
            'policy_window_minutes' => 15,
            'progress_label' => sprintf('%d / 5 failed attempts in the last 15 minutes', $progress),
            'status_label' => $statusLabel,
            'lockout_until' => $user->lockedUntil,
            'unlock_action_url' => '/admin/users/' . $userId . '/unlock',
            'reset_password_url' => '/admin/users/' . $userId . '/reset-password',
        ]);
    }
}
