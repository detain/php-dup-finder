<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Service\SlugGenerator;
use App\Exception\FormException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ArticleFormHandler
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly SlugGenerator $slugGenerator,
    ) {}

    public function handleCreate(array $data, ?UploadedFile $featuredImage = null): Article
    {
        $errors = $this->validate($data);

        if (!empty($errors)) {
            throw new FormException($errors);
        }

        $slug = $this->slugGenerator->generate($data['title']);

        if ($this->articleRepository->findBySlug($slug) !== null) {
            $slug = $slug . '-' . time();
        }

        $article = new Article(
            $data['title'],
            $data['content'],
            $data['excerpt'] ?? null,
            $data['category_id']
        );

        $article->setSlug($slug);
        $article->setAuthorId($data['author_id']);
        $article->setStatus($data['status'] ?? 'draft');

        if ($featuredImage !== null) {
            $article->setFeaturedImage($this->handleImageUpload($featuredImage, 'articles'));
        }

        $this->articleRepository->save($article);

        return $article;
    }

    public function handleUpdate(Article $article, array $data, ?UploadedFile $featuredImage = null): Article
    {
        $errors = $this->validateUpdate($data);

        if (!empty($errors)) {
            throw new FormException($errors);
        }

        if (isset($data['title']) && $data['title'] !== $article->getTitle()) {
            $article->setTitle($data['title']);
            $article->setSlug($this->slugGenerator->generate($data['title']));
        }

        if (isset($data['content'])) {
            $article->setContent($data['content']);
        }

        if (isset($data['excerpt'])) {
            $article->setExcerpt($data['excerpt']);
        }

        if (isset($data['category_id'])) {
            $article->setCategoryId($data['category_id']);
        }

        if (isset($data['status'])) {
            $article->setStatus($data['status']);
        }

        if ($featuredImage !== null) {
            $article->setFeaturedImage($this->handleImageUpload($featuredImage, 'articles'));
        }

        $this->articleRepository->save($article);

        return $article;
    }

    private function validate(array $data): array
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors['title'] = 'Title is required';
        } elseif (strlen($data['title']) < 5) {
            $errors['title'] = 'Title must be at least 5 characters';
        } elseif (strlen($data['title']) > 255) {
            $errors['title'] = 'Title must not exceed 255 characters';
        }

        if (empty($data['content'])) {
            $errors['content'] = 'Content is required';
        } elseif (strlen($data['content']) < 50) {
            $errors['content'] = 'Content must be at least 50 characters';
        }

        if (empty($data['category_id'])) {
            $errors['category_id'] = 'Category is required';
        }

        if (empty($data['author_id'])) {
            $errors['author_id'] = 'Author is required';
        }

        return $errors;
    }

    private function validateUpdate(array $data): array
    {
        $errors = [];

        if (isset($data['title'])) {
            if (strlen($data['title']) < 5) {
                $errors['title'] = 'Title must be at least 5 characters';
            } elseif (strlen($data['title']) > 255) {
                $errors['title'] = 'Title must not exceed 255 characters';
            }
        }

        if (isset($data['content']) && strlen($data['content']) < 50) {
            $errors['content'] = 'Content must be at least 50 characters';
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
