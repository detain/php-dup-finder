<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FormableInterface;
use App\Repository\FormableRepositoryInterface;
use App\Service\SlugGeneratorInterface;
use App\Exception\FormException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

interface FormHandlerInterface
{
    public function handleCreate(array $data, ?UploadedFile $file = null): FormableInterface;
    public function handleUpdate(FormableInterface $entity, array $data, ?UploadedFile $file = null): FormableInterface;
}

abstract class AbstractFormHandler implements FormHandlerInterface
{
    protected const REQUIRED_FIELDS = [];

    public function __construct(
        protected readonly FormableRepositoryInterface $repository,
        protected readonly SlugGeneratorInterface $slugGenerator,
    ) {}

    public function handleCreate(array $data, ?UploadedFile $file = null): FormableInterface
    {
        $errors = $this->validate($data);

        if (!empty($errors)) {
            throw new FormException($errors);
        }

        $slug = $this->slugGenerator->generate($this->getSlugSource($data));
        if ($this->repository->findBySlug($slug) !== null) {
            $slug = $slug . '-' . time();
        }

        $entity = $this->createEntity($data);
        $entity->setSlug($slug);
        $this->setEntityDefaults($entity, $data);

        if ($file !== null) {
            $this->setEntityImage($entity, $file);
        }

        $this->repository->save($entity);

        return $entity;
    }

    public function handleUpdate(FormableInterface $entity, array $data, ?UploadedFile $file = null): FormableInterface
    {
        $errors = $this->validateUpdate($data);

        if (!empty($errors)) {
            throw new FormException($errors);
        }

        if ($this->shouldUpdateSlug($entity, $data)) {
            $entity->setSlug($this->slugGenerator->generate($this->getSlugSource($data)));
        }

        $this->updateEntity($entity, $data);

        if ($file !== null) {
            $this->setEntityImage($entity, $file);
        }

        $this->repository->save($entity);

        return $entity;
    }

    protected function validate(array $data): array
    {
        $errors = [];

        foreach (static::REQUIRED_FIELDS as $field) {
            if (empty($data[$field]) && $data[$field] !== '0' && $data[$field] !== 0) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }

        return $errors;
    }

    protected function validateUpdate(array $data): array
    {
        return [];
    }

    abstract protected function getSlugSource(array $data): string;
    abstract protected function createEntity(array $data): FormableInterface;
    abstract protected function setEntityDefaults(FormableInterface $entity, array $data): void;
    abstract protected function setEntityImage(FormableInterface $entity, UploadedFile $file): void;
    abstract protected function updateEntity(FormableInterface $entity, array $data): void;
    abstract protected function shouldUpdateSlug(FormableInterface $entity, array $data): bool;

    protected function handleImageUpload(UploadedFile $file, string $type): string
    {
        $filename = sprintf('%s_%s.%s', $type, time(), $file->guessExtension());
        $file->move(__DIR__ . '/../../public/uploads/' . $type, $filename);
        return '/uploads/' . $type . '/' . $filename;
    }
}
