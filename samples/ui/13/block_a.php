<?php

declare(strict_types=1);

namespace App\View\Card;

use App\Entity\User;
use Psr\Log\LoggerInterface;

final class UserCardRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderCard(User $user, array $options = []): string
    {
        $showAvatar = $options['showAvatar'] ?? true;
        $showActions = $options['showActions'] ?? true;
        $compact = $options['compact'] ?? false;

        $statusClass = match ($user->getStatus()) {
            'active' => 'user-status-active',
            'inactive' => 'user-status-inactive',
            'pending' => 'user-status-pending',
            default => '',
        };

        $html = '<article class="user-card';
        if ($compact) {
            $html .= ' card-compact';
        }
        $html .= '" data-user-id="' . $user->getId() . '">';

        if ($showAvatar) {
            $html .= '<div class="card-avatar">';
            $avatarUrl = $user->getAvatarUrl() ?? $this->generateAvatarUrl($user);
            $html .= '<img src="' . htmlspecialchars($avatarUrl) . '" alt="' . htmlspecialchars($user->getFullName()) . '" class="avatar-image" />';
            $html .= '<span class="status-indicator ' . $statusClass . '"></span>';
            $html .= '</div>';
        }

        $html .= '<div class="card-content">';
        $html .= '<h3 class="card-title">' . htmlspecialchars($user->getFullName()) . '</h3>';
        $html .= '<p class="card-subtitle">' . htmlspecialchars($user->getEmail()) . '</p>';

        if (!$compact) {
            $html .= '<div class="card-meta">';
            $html .= '<span class="meta-item">';
            $html .= '<span class="meta-label">Joined:</span> ';
            $html .= '<span class="meta-value">' . $user->getCreatedAt()->format('M Y') . '</span>';
            $html .= '</span>';
            $html .= '<span class="meta-item">';
            $html .= '<span class="meta-label">Status:</span> ';
            $html .= '<span class="meta-value status-text ' . $statusClass . '">' . ucfirst($user->getStatus()) . '</span>';
            $html .= '</span>';
            $html .= '</div>';
        }

        if ($showActions) {
            $html .= '<div class="card-actions">';
            $html .= '<a href="/users/' . $user->getId() . '" class="btn-view">View Profile</a>';
            $html .= '<a href="/users/' . $user->getId() . '/edit" class="btn-edit">Edit</a>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</article>';

        $this->logger->debug('Rendered user card', ['user_id' => $user->getId()]);

        return $html;
    }

    public function renderCardGrid(array $users, array $options = []): string
    {
        $columns = $options['columns'] ?? 3;

        $html = '<div class="card-grid grid-cols-' . $columns . '">';

        if (empty($users)) {
            $html .= '<div class="empty-state">No users to display</div>';
        } else {
            foreach ($users as $user) {
                $html .= $this->renderCard($user, $options);
            }
        }

        $html .= '</div>';

        return $html;
    }

    private function generateAvatarUrl(User $user): string
    {
        $name = urlencode($user->getFullName());
        return 'https://ui-avatars.com/api/?name=' . $name . '&background=random';
    }
}
