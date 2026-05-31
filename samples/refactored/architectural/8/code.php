<?php
declare(strict_types=1);

namespace App\Admin;

abstract class AdminForm
{
    /** @var list<string> */
    public array $errors = [];

    abstract public function bind(array $post): void;

    abstract protected function rules(): array;

    public function isValid(): bool
    {
        $this->errors = [];
        foreach ($this->rules() as $err => $ok) {
            if (!$ok) {
                $this->errors[] = $err;
            }
        }
        return $this->errors === [];
    }
}

interface AdminPresenter
{
    public function present(array $row): array;
}

final class AdminController
{
    public function __construct(
        private \PDO $pdo,
        private string $table,
        private AdminPresenter $presenter,
        private AdminForm $formPrototype,
    ) {}

    public function edit(int $id, array $post): array
    {
        $form = clone $this->formPrototype;
        if ($post !== []) {
            $form->bind($post);
            if ($form->isValid()) {
                $row = (array) $form;
                $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($row)));
                $stmt = $this->pdo->prepare("UPDATE {$this->table} SET {$set} WHERE id = ?");
                $stmt->execute([...array_values($row), $id]);
            }
        }
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['form' => $form, 'view' => $this->presenter->present($row)];
    }
}
