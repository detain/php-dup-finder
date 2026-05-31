<?php
declare(strict_types=1);

namespace App\Admin\Users;

final class UserForm
{
    public string $email = '';
    public string $role = 'member';

    /** @var list<string> */
    public array $errors = [];

    public function bind(array $post): void
    {
        $this->email = trim((string) ($post['email'] ?? ''));
        $this->role = (string) ($post['role'] ?? 'member');
    }

    public function isValid(): bool
    {
        $this->errors = [];
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = 'invalid email';
        }
        if (!in_array($this->role, ['member', 'admin'], true)) {
            $this->errors[] = 'invalid role';
        }
        return $this->errors === [];
    }
}

final class UserPresenter
{
    public function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'role' => ucfirst((string) $row['role']),
            'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format('Y-m-d'),
        ];
    }
}

final class UserAdminController
{
    public function __construct(private \PDO $pdo, private UserPresenter $presenter) {}

    public function edit(int $id, array $post): array
    {
        $form = new UserForm();
        if ($post !== []) {
            $form->bind($post);
            if ($form->isValid()) {
                $stmt = $this->pdo->prepare('UPDATE users SET email = ?, role = ? WHERE id = ?');
                $stmt->execute([$form->email, $form->role, $id]);
            }
        }
        $stmt = $this->pdo->prepare('SELECT id, email, role, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['form' => $form, 'view' => $this->presenter->present($row)];
    }
}
