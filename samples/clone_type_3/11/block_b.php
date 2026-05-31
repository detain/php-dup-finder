<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\SlugGenerator;
use App\Exception\FormException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProductFormHandler
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly SlugGenerator $slugGenerator,
    ) {}

    public function handleCreate(array $data, ?UploadedFile $image = null): Product
    {
        $errors = $this->validate($data);

        if (!empty($errors)) {
            throw new FormException($errors);
        }

        $slug = $this->slugGenerator->generate($data['name']);

        if ($this->productRepository->findBySlug($slug) !== null) {
            $slug = $slug . '-' . time();
        }

        $product = new Product(
            $data['sku'],
            $data['name'],
            (float) $data['price'],
            $data['description'] ?? null
        );

        $product->setSlug($slug);
        $product->setCategoryId($data['category_id']);
        $product->setStock((int) ($data['stock'] ?? 0));
        $product->setStatus($data['status'] ?? 'active');

        if ($image !== null) {
            $product->setImage($this->handleImageUpload($image, 'products'));
        }

        $this->productRepository->save($product);

        return $product;
    }

    public function handleUpdate(Product $product, array $data, ?UploadedFile $image = null): Product
    {
        $errors = $this->validateUpdate($data);

        if (!empty($errors)) {
            throw new FormException($errors);
        }

        if (isset($data['name']) && $data['name'] !== $product->getName()) {
            $product->setName($data['name']);
            $product->setSlug($this->slugGenerator->generate($data['name']));
        }

        if (isset($data['sku'])) {
            $product->setSku($data['sku']);
        }

        if (isset($data['price'])) {
            $product->setPrice((float) $data['price']);
        }

        if (isset($data['description'])) {
            $product->setDescription($data['description']);
        }

        if (isset($data['category_id'])) {
            $product->setCategoryId($data['category_id']);
        }

        if (isset($data['stock'])) {
            $product->setStock((int) $data['stock']);
        }

        if (isset($data['status'])) {
            $product->setStatus($data['status']);
        }

        if ($image !== null) {
            $product->setImage($this->handleImageUpload($image, 'products'));
        }

        $this->productRepository->save($product);

        return $product;
    }

    private function validate(array $data): array
    {
        $errors = [];

        if (empty($data['sku'])) {
            $errors['sku'] = 'SKU is required';
        } elseif ($this->productRepository->findBySku($data['sku']) !== null) {
            $errors['sku'] = 'SKU already exists';
        }

        if (empty($data['name'])) {
            $errors['name'] = 'Name is required';
        } elseif (strlen($data['name']) < 3) {
            $errors['name'] = 'Name must be at least 3 characters';
        } elseif (strlen($data['name']) > 255) {
            $errors['name'] = 'Name must not exceed 255 characters';
        }

        if (empty($data['price'])) {
            $errors['price'] = 'Price is required';
        } elseif (!is_numeric($data['price']) || $data['price'] < 0) {
            $errors['price'] = 'Price must be a positive number';
        }

        if (empty($data['category_id'])) {
            $errors['category_id'] = 'Category is required';
        }

        return $errors;
    }

    private function validateUpdate(array $data): array
    {
        $errors = [];

        if (isset($data['sku']) && $this->productRepository->findBySku($data['sku']) !== null) {
            $errors['sku'] = 'SKU already exists';
        }

        if (isset($data['name'])) {
            if (strlen($data['name']) < 3) {
                $errors['name'] = 'Name must be at least 3 characters';
            } elseif (strlen($data['name']) > 255) {
                $errors['name'] = 'Name must not exceed 255 characters';
            }
        }

        if (isset($data['price']) && (!is_numeric($data['price']) || $data['price'] < 0)) {
            $errors['price'] = 'Price must be a positive number';
        }

        return $errors;
    }

    private function handleImageUpload(UploadedFile $file, string $type): string
    {
        $filename = sprintf('%s_%s.%s', $type, time(), $file->guessExtension());
        $file->move(__DIR__ . '/../../public/uploads/' . $type, $filename);
        return '/uploads/' . $type . '/' . $filename;
    }
}
