<?php

declare(strict_types=1);

namespace App\Session;

interface SessionStateManagerInterface
{
    public function saveState(mixed $entity): void;
    public function restoreState(): ?array;
    public function clearState(): void;
    public function hasState(): bool;
    public function refreshState(mixed $entity): void;
}

abstract class AbstractSessionStateManager implements SessionStateManagerInterface
{
    protected SessionStore $session;
    protected string $stateKey;
    protected string $idKey;
    protected string $type;

    public function __construct(SessionStore $session, string $type)
    {
        $this->session = $session;
        $this->type = $type;
        $this->stateKey = "{$type}_state";
        $this->idKey = "{$type}_id";
    }

    public function hasState(): bool
    {
        return $this->session->has($this->stateKey);
    }

    public function clearState(): void
    {
        $this->session->remove($this->stateKey);
        $this->session->remove($this->idKey);
        $this->session->remove("{$this->type}_item_count");
    }

    public function refreshState(mixed $entity): void
    {
        $this->saveState($entity);
    }

    public function getStateTimestamp(): ?int
    {
        $state = $this->session->get($this->stateKey);

        if ($state === null || !isset($state['saved_at'])) {
            return null;
        }

        return $state['saved_at'];
    }

    protected function buildState(mixed $entity, array $additionalFields): array
    {
        $state = array_merge(
            $this->extractBaseFields($entity),
            $additionalFields,
            ['saved_at' => time()]
        );

        $this->session->set($this->stateKey, $state);
        $this->session->set($this->idKey, $state['id']);

        return $state;
    }

    protected function restoreStateWithDates(): ?array
    {
        $state = $this->session->get($this->stateKey);

        if ($state === null) {
            return null;
        }

        if (isset($state['created_at'])) {
            $state['created_at'] = new \DateTimeImmutable($state['created_at']);
        }

        if (isset($state['updated_at']) && $state['updated_at'] !== null) {
            $state['updated_at'] = new \DateTimeImmutable($state['updated_at']);
        }

        unset($state['saved_at']);

        return $state;
    }

    abstract protected function extractBaseFields(mixed $entity): array;
}

class UserSessionManager extends AbstractSessionStateManager
{
    public function __construct(SessionStore $session)
    {
        parent::__construct($session, 'user');
    }

    public function saveState(User $user): void
    {
        $this->buildState($user, [
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'avatar_url' => $user->getAvatarUrl(),
            'is_active' => $user->isActive(),
            'roles' => $user->getRoles()
        ]);

        $this->session->set('user_roles', $user->getRoles());
    }

    public function restoreState(): ?array
    {
        return $this->restoreStateWithDates();
    }

    protected function extractBaseFields(mixed $entity): array
    {
        return [
            'id' => $entity->getId(),
            'created_at' => $entity->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $entity->getUpdatedAt()?->format('Y-m-d H:i:s')
        ];
    }

    public function isUserInRole(string $role): bool
    {
        $roles = $this->session->get('user_roles', []);
        return in_array($role, $roles, true);
    }
}

class ShoppingCartSessionManager extends AbstractSessionStateManager
{
    public function __construct(SessionStore $session)
    {
        parent::__construct($session, 'cart');
    }

    public function saveState(ShoppingCart $cart): void
    {
        $state = $this->buildState($cart, [
            'user_id' => $cart->getUserId(),
            'items' => $this->serializeItems($cart->getItems()),
            'total_amount' => $cart->getTotalAmount(),
            'currency' => $cart->getCurrency(),
            'is_active' => $cart->isActive()
        ]);

        $this->session->set('cart_item_count', count($cart->getItems()));
    }

    public function restoreState(): ?array
    {
        $state = $this->restoreStateWithDates();

        if ($state !== null && isset($state['items'])) {
            $state['items'] = $this->deserializeItems($state['items']);
        }

        return $state;
    }

    protected function extractBaseFields(mixed $entity): array
    {
        return [
            'id' => $entity->getId(),
            'created_at' => $entity->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $entity->getUpdatedAt()?->format('Y-m-d H:i:s')
        ];
    }

    private function serializeItems(array $items): array
    {
        return array_map(fn($item) => [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price']
        ], $items);
    }

    private function deserializeItems(array $items): array
    {
        return array_map(fn($item) => [
            'product_id' => $item['product_id'],
            'quantity' => (int)$item['quantity'],
            'unit_price' => (float)$item['unit_price']
        ], $items);
    }

    public function getCartItemCount(): int
    {
        return (int)$this->session->get('cart_item_count', 0);
    }
}

class SessionStateManagerRegistry
{
    private array $managers = [];

    public function register(string $type, SessionStateManagerInterface $manager): void
    {
        $this->managers[$type] = $manager;
    }

    public function getManager(string $type): ?SessionStateManagerInterface
    {
        return $this->managers[$type] ?? null;
    }

    public function clearAll(): void
    {
        foreach ($this->managers as $manager) {
            $manager->clearState();
        }
    }
}
