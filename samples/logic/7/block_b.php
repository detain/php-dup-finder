<?php

declare(strict_types=1);

namespace App\BusinessDirectory;

use App\Entity\Business;
use App\Repository\BusinessRepository;
use App\Service\InputSanitizer;
use Psr\Log\LoggerInterface;

final class BusinessListingService
{
    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly InputSanitizer $inputSanitizer,
        private readonly LoggerInterface $logger,
    ) {}

    public function updateListing(int $businessId, array $listingData): Business
    {
        $business = $this->businessRepository->findById($businessId);

        if ($business === null) {
            throw new \RuntimeException('Business not found');
        }

        if (isset($listingData['business_name'])) {
            $name = trim($listingData['business_name']);

            if (strlen($name) < 2) {
                throw new \InvalidArgumentException('Business name is too short');
            }

            if (strlen($name) > 100) {
                throw new \InvalidArgumentException('Business name cannot exceed 100 characters');
            }

            if (!preg_match('/^[a-zA-Z0-9\s\&\-\'\.\,]+$/', $name)) {
                throw new \InvalidArgumentException('Business name contains invalid characters');
            }

            $business->setBusinessName($name);
        }

        if (isset($listingData['description'])) {
            $description = trim($listingData['description']);

            if (strlen($description) < 10) {
                throw new \InvalidArgumentException('Description is too short');
            }

            if (strlen($description) > 2000) {
                throw new \InvalidArgumentException('Description cannot exceed 2000 characters');
            }

            if (!preg_match('/^[a-zA-Z0-9\s\-\_\.\,\!\?\;\'\"\&]+$/', $description)) {
                throw new \InvalidArgumentException('Description contains invalid characters');
            }

            $business->setDescription($description);
        }

        if (isset($listingData['category'])) {
            $category = trim($listingData['category']);

            if (strlen($category) < 2) {
                throw new \InvalidArgumentException('Category is too short');
            }

            if (strlen($category) > 50) {
                throw new \InvalidArgumentException('Category cannot exceed 50 characters');
            }

            if (!preg_match('/^[a-zA-Z0-9\s\-\&]+$/', $category)) {
                throw new \InvalidArgumentException('Category contains invalid characters');
            }

            $business->setCategory($category);
        }

        if (isset($listingData['email'])) {
            $email = trim($listingData['email']);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid email format');
            }

            if (strlen($email) > 100) {
                throw new \InvalidArgumentException('Email cannot exceed 100 characters');
            }

            $business->setEmail($email);
        }

        if (isset($listingData['website'])) {
            $website = trim($listingData['website']);

            if (strlen($website) > 200) {
                throw new \InvalidArgumentException('Website URL cannot exceed 200 characters');
            }

            if (!filter_var($website, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Invalid website URL');
            }

            $business->setWebsite($website);
        }

        $this->businessRepository->save($business);

        $this->logger->info('Business listing updated', [
            'business_id' => $businessId,
            'updated_fields' => array_keys($listingData),
        ]);

        return $business;
    }

    public function updateHours(int $businessId, array $hoursData): Business
    {
        $business = $this->businessRepository->findById($businessId);

        if ($business === null) {
            throw new \RuntimeException('Business not found');
        }

        if (isset($hoursData['monday'])) {
            $hours = trim($hoursData['monday']);

            if (!preg_match('/^[0-9]{1,2}:[0-9]{2}\s*-\s*[0-9]{1,2}:[0-9]{2}$/', $hours)) {
                throw new \InvalidArgumentException('Monday hours must be in format: HH:MM - HH:MM');
            }

            $business->setMondayHours($hours);
        }

        if (isset($hoursData['tuesday'])) {
            $hours = trim($hoursData['tuesday']);

            if (!preg_match('/^[0-9]{1,2}:[0-9]{2}\s*-\s*[0-9]{1,2}:[0-9]{2}$/', $hours)) {
                throw new \InvalidArgumentException('Tuesday hours must be in format: HH:MM - HH:MM');
            }

            $business->setTuesdayHours($hours);
        }

        if (isset($hoursData['wednesday'])) {
            $hours = trim($hoursData['wednesday']);

            if (!preg_match('/^[0-9]{1,2}:[0-9]{2}\s*-\s*[0-9]{1,2}:[0-9]{2}$/', $hours)) {
                throw new \InvalidArgumentException('Wednesday hours must be in format: HH:MM - HH:MM');
            }

            $business->setWednesdayHours($hours);
        }

        $this->businessRepository->save($business);

        return $business;
    }
}
