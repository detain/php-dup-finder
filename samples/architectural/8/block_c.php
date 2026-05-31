<?php
declare(strict_types=1);

namespace App\Admin\Categories;

final class CategoryForm
{
    public string $name = '';
    public int $parentId = 0;

    /** @var list<string> */
    public array $errors = [];

    public function bind(array $post): void
    {
        $this->name = trim((string) ($post['name'] ?? ''));
        $this->parentId = (int) ($post['parentId'] ?? 0);
    }

    public function isValid(): bool
    {
        $this->errors = [];
        if ($this->name === '') {
            $this->errors[] = 'name required';
        }
        if ($this->parentId < 0) {
            $this->errors[] = 'invalid parent';
        }
        return $this->errors === [];
    }
}

final class CategoryPresenter
{
    public function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'parentId' => (int) ($row['parent_id'] ?? 0),
            'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format('Y-m-d'),
        ];
    }
}

final class CategoryAdminController
{
    public function __construct(private \PDO $pdo, private CategoryPresenter $presenter) {}

    public function edit(int $id, array $post): array
    {
        $form = new CategoryForm();
        if ($post !== []) {
            $form->bind($post);
            if ($form->isValid()) {
                $stmt = $this->pdo->prepare('UPDATE categories SET name = ?, parent_id = ? WHERE id = ?');
                $stmt->execute([$form->name, $form->parentId, $id]);
            }
        }
        $stmt = $this->pdo->prepare('SELECT id, name, parent_id, created_at FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['form' => $form, 'view' => $this->presenter->present($row)];
    }
}
