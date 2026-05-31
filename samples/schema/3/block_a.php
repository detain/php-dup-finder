<?php

declare(strict_types=1);

namespace App\Domain\Product\Entity;

/**
 * Doctrine entity for Product.
 * This entity is duplicated in:
 * - Database table: products, product_inventory, product_images
 * - Elasticsearch index mapping
 * - GraphQL schema
 * - OpenAPI spec
 *
 * @ORM\Entity(repositoryClass=ProductRepository::class)
 * @ORM\Table(name="products")
 * @ORM\Index(name="idx_sku", columns={"sku"}, unique=true)
 * @ORM\Index(name="idx_category", columns={"category"})
 * @ORM\Index(name="idx_is_active", columns={"is_active"})
 */
class Product
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $sku;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $name;

    /**
     * @ORM\Column(type="text")
     */
    private string $description;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private string $category;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private float $price;

    /**
     * @ORM\Column(type="char", length=3)
     */
    private string $currency = 'USD';

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $isActive = true;

    /**
     * @ORM\Column(type="json")
     */
    private array $attributes = [];

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $updatedAt;

    /**
     * @ORM\OneToMany(targetEntity=ProductImage::class, mappedBy="product", cascade={"persist", "remove"})
     * @ORM\OrderBy({"displayOrder" = "ASC"})
     */
    private Collection $images;

    /**
     * @ORM\OneToOne(targetEntity=ProductInventory::class, mappedBy="product", cascade={"persist", "remove"})
     */
    private ?ProductInventory $inventory = null;

    public function __construct(
        string $sku,
        string $name,
        string $description,
        string $category,
        float $price,
        string $currency = 'USD'
    ) {
        $this->id = \App\Domain\Product\ValueObject\ProductId::generate()->toString();
        $this->sku = $sku;
        $this->name = $name;
        $this->description = $description;
        $this->category = $category;
        $this->price = $price;
        $this->currency = $currency;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->images = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getImages(): array
    {
        return $this->images->toArray();
    }

    public function addImage(string $url, int $displayOrder = 0): void
    {
        $image = new ProductImage($this, $url, $displayOrder);
        $this->images->add($image);
    }

    public function getInventory(): ?ProductInventory
    {
        return $this->inventory;
    }

    public function setInventory(ProductInventory $inventory): void
    {
        $this->inventory = $inventory;
        $inventory->setProduct($this);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'price' => $this->price,
            'currency' => $this->currency,
            'is_active' => $this->isActive,
            'attributes' => $this->attributes,
            'images' => array_map(fn($img) => $img->toArray(), $this->images->toArray()),
            'inventory' => $this->inventory?->toArray(),
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeImmutable::ATOM),
        ];
    }
}
