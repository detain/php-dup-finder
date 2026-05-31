<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\SlugGenerator;
use App\Exception\FormException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class EventFormHandler
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly SlugGenerator $slugGenerator,
    ) {}

    public function handleCreate(array $data, ?UploadedFile $banner = null): Event
    {
        $errors = $this->validate($data);

        if (!empty($errors)) {
            throw new FormException($errors);
        }

        $slug = $this->slugGenerator->generate($data['title']);

        if ($this->eventRepository->findBySlug($slug) !== null) {
            $slug = $slug . '-' . time();
        }

        $event = new Event(
            $data['title'],
            new \DateTime($data['start_date']),
            new \DateTime($data['end_date']),
            $data['venue']
        );

        $event->setSlug($slug);
        $event->setDescription($data['description'] ?? null);
        $event->setCapacity((int) ($data['capacity'] ?? 0));
        $event->setStatus($data['status'] ?? 'draft');

        if ($banner !== null) {
            $event->setBanner($this->handleImageUpload($banner, 'events'));
        }

        $this->eventRepository->save($event);

        return $event;
    }

    public function handleUpdate(Event $event, array $data, ?UploadedFile $banner = null): Event
    {
        $errors = $this->validateUpdate($data);

        if (!empty($errors)) {
            throw new FormException($errors);
        }

        if (isset($data['title']) && $data['title'] !== $event->getTitle()) {
            $event->setTitle($data['title']);
            $event->setSlug($this->slugGenerator->generate($data['title']));
        }

        if (isset($data['start_date'])) {
            $event->setStartDate(new \DateTime($data['start_date']));
        }

        if (isset($data['end_date'])) {
            $event->setEndDate(new \DateTime($data['end_date']));
        }

        if (isset($data['venue'])) {
            $event->setVenue($data['venue']);
        }

        if (isset($data['description'])) {
            $event->setDescription($data['description']);
        }

        if (isset($data['capacity'])) {
            $event->setCapacity((int) $data['capacity']);
        }

        if (isset($data['status'])) {
            $event->setStatus($data['status']);
        }

        if ($banner !== null) {
            $event->setBanner($this->handleImageUpload($banner, 'events'));
        }

        $this->eventRepository->save($event);

        return $event;
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

        if (empty($data['start_date'])) {
            $errors['start_date'] = 'Start date is required';
        } elseif (strtotime($data['start_date']) === false) {
            $errors['start_date'] = 'Invalid start date format';
        }

        if (empty($data['end_date'])) {
            $errors['end_date'] = 'End date is required';
        } elseif (strtotime($data['end_date']) === false) {
            $errors['end_date'] = 'Invalid end date format';
        }

        if (empty($data['venue'])) {
            $errors['venue'] = 'Venue is required';
        }

        if (isset($data['capacity']) && (!is_numeric($data['capacity']) || $data['capacity'] < 0)) {
            $errors['capacity'] = 'Capacity must be a non-negative number';
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

        if (isset($data['start_date']) && strtotime($data['start_date']) === false) {
            $errors['start_date'] = 'Invalid start date format';
        }

        if (isset($data['end_date']) && strtotime($data['end_date']) === false) {
            $errors['end_date'] = 'Invalid end date format';
        }

        if (isset($data['capacity']) && (!is_numeric($data['capacity']) || $data['capacity'] < 0)) {
            $errors['capacity'] = 'Capacity must be a non-negative number';
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
