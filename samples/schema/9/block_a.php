<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Entity;

use App\Domain\Authorization\ValueObject\RoleId;
use App\Domain\Authorization\ValueObject\PermissionId;

/**
 * Doctrine entity for roles and permissions.
 * This entity is duplicated in:
 * - Database tables: roles, permissions, role_permissions, user_roles
 * - Authorization service schemas
 * - Admin dashboard schemas
 * - API documentation
 *
 * @ORM\Entity(repositoryClass=RoleRepository::class)
 * @ORM\Table(name="roles")
 * @ORM\Index(name="idx_role_name", columns={"name"}, unique=true)
 */
class Role
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $name;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private string $slug;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $roleType;

    /**
     * @ORM\Column(type="integer")
     */
    private int $priority = 0;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $isSystem = false;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $constraints = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $updatedAt;

    /**
     * @ORM\ManyToMany(targetEntity=Permission::class, inversedBy="roles")
     * @ORM\JoinTable(
     *     name="role_permissions",
     *     joinColumns={"role_id" = "id"},
     *     inverseJoinColumns={"permission_id" = "id"}
     * )
     */
    private Collection $permissions;

    public function __construct(
        string $name,
        string $slug,
        string $roleType = 'custom'
    ) {
        $this->id = RoleId::generate()->toString();
        $this->name = $name;
        $this->slug = $slug;
        $this->roleType = $roleType;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->permissions = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getRoleType(): string
    {
        return $this->roleType;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPermissions(): array
    {
        return $this->permissions->toArray();
    }

    public function hasPermission(string $permissionSlug): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->getSlug() === $permissionSlug) {
                return true;
            }
        }
        return false;
    }

    public function addPermission(Permission $permission): void
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function removePermission(Permission $permission): void
    {
        $this->permissions->removeElement($permission);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function syncPermissions(array $permissions): void
    {
        $this->permissions->clear();
        foreach ($permissions as $permission) {
            $this->permissions->add($permission);
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'role_type' => $this->roleType,
            'priority' => $this->priority,
            'is_system' => $this->isSystem,
            'permissions' => array_map(fn($p) => $p->toArray(), $this->permissions->toArray()),
            'constraints' => $this->constraints,
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeImmutable::ATOM),
        ];
    }
}

/**
 * Permission entity.
 *
 * @ORM\Entity(repositoryClass=PermissionRepository::class)
 * @ORM\Table(name="permissions")
 * @ORM\Index(name="idx_permission_slug", columns={"slug"}, unique=true)
 * @ORM\Index(name="idx_permission_category", columns={"category"})
 */
class Permission
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $name;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $slug;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private string $category;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $resource = null;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $action = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\ManyToMany(targetEntity=Role::class, mappedBy="permissions")
     */
    private Collection $roles;

    public function __construct(
        string $name,
        string $slug,
        string $category,
        ?string $resource = null,
        ?string $action = null
    ) {
        $this->id = PermissionId::generate()->toString();
        $this->name = $name;
        $this->slug = $slug;
        $this->category = $category;
        $this->resource = $resource;
        $this->action = $action;
        $this->createdAt = new \DateTimeImmutable();
        $this->roles = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function matches(string $resource, string $action): bool
    {
        if ($this->resource !== null && $this->resource !== $resource) {
            return false;
        }

        if ($this->action !== null && $this->action !== $action) {
            return false;
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'resource' => $this->resource,
            'action' => $this->action,
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
        ];
    }
}
