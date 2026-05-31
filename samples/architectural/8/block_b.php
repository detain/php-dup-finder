<?php
declare(strict_types=1);

namespace App\Admin\Articles;

final class ArticleForm
{
    public string $title = '';
    public string $body = '';

    /** @var list<string> */
    public array $errors = [];

    public function bind(array $post): void
    {
        $this->title = trim((string) ($post['title'] ?? ''));
        $this->body = (string) ($post['body'] ?? '');
    }

    public function isValid(): bool
    {
        $this->errors = [];
        if ($this->title === '') {
            $this->errors[] = 'title required';
        }
        if (strlen($this->body) < 10) {
            $this->errors[] = 'body too short';
        }
        return $this->errors === [];
    }
}

final class ArticlePresenter
{
    public function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'excerpt' => substr((string) $row['body'], 0, 80),
            'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format('Y-m-d'),
        ];
    }
}

final class ArticleAdminController
{
    public function __construct(private \PDO $pdo, private ArticlePresenter $presenter) {}

    public function edit(int $id, array $post): array
    {
        $form = new ArticleForm();
        if ($post !== []) {
            $form->bind($post);
            if ($form->isValid()) {
                $stmt = $this->pdo->prepare('UPDATE articles SET title = ?, body = ? WHERE id = ?');
                $stmt->execute([$form->title, $form->body, $id]);
            }
        }
        $stmt = $this->pdo->prepare('SELECT id, title, body, created_at FROM articles WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['form' => $form, 'view' => $this->presenter->present($row)];
    }
}
