<?php

declare(strict_types=1);

namespace App\UserManagement;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\InputSanitizer;
use Psr\Log\LoggerInterface;

final class UserProfileService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly InputSanitizer $inputSanitizer,
        private readonly LoggerInterface $logger,
    ) {}

    public function updateProfile(int $userId, array $profileData): User
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        if (isset($profileData['first_name'])) {
            $firstName = trim($profileData['first_name']);

            if (strlen($firstName) < 1) {
                throw new \InvalidArgumentException('First name cannot be empty');
            }

            if (strlen($firstName) > 50) {
                throw new \InvalidArgumentException('First name cannot exceed 50 characters');
            }

            if (!preg_match('/^[a-zA-Z\s\-\']+$/', $firstName)) {
                throw new \InvalidArgumentException('First name contains invalid characters');
            }

            $user->setFirstName($firstName);
        }

        if (isset($profileData['last_name'])) {
            $lastName = trim($profileData['last_name']);

            if (strlen($lastName) < 1) {
                throw new \InvalidArgumentException('Last name cannot be empty');
            }

            if (strlen($lastName) > 50) {
                throw new \InvalidArgumentException('Last name cannot exceed 50 characters');
            }

            if (!preg_match('/^[a-zA-Z\s\-\']+$/', $lastName)) {
                throw new \InvalidArgumentException('Last name contains invalid characters');
            }

            $user->setLastName($lastName);
        }

        if (isset($profileData['bio'])) {
            $bio = trim($profileData['bio']);

            if (strlen($bio) > 500) {
                throw new \InvalidArgumentException('Bio cannot exceed 500 characters');
            }

            if (!preg_match('/^[a-zA-Z0-9\s\-\_\.\,\!\?\;]+$/', $bio)) {
                throw new \InvalidArgumentException('Bio contains invalid characters');
            }

            $user->setBio($bio);
        }

        if (isset($profileData['phone'])) {
            $phone = trim($profileData['phone']);

            if (!preg_match('/^\+?[1-9]\d{6,14}$/', $phone)) {
                throw new \InvalidArgumentException('Invalid phone number format');
            }

            $user->setPhone($phone);
        }

        if (isset($profileData['website'])) {
            $website = trim($profileData['website']);

            if (strlen($website) > 200) {
                throw new \InvalidArgumentException('Website URL cannot exceed 200 characters');
            }

            if (!filter_var($website, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Invalid website URL');
            }

            $user->setWebsite($website);
        }

        $this->userRepository->save($user);

        $this->logger->info('User profile updated', [
            'user_id' => $userId,
            'updated_fields' => array_keys($profileData),
        ]);

        return $user;
    }

    public function updateAddress(int $userId, array $addressData): User
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        if (isset($addressData['street'])) {
            $street = trim($addressData['street']);

            if (strlen($street) < 5) {
                throw new \InvalidArgumentException('Street address is too short');
            }

            if (strlen($street) > 100) {
                throw new \InvalidArgumentException('Street address cannot exceed 100 characters');
            }

            if (!preg_match('/^[a-zA-Z0-9\s\-\,\.]+$/', $street)) {
                throw new \InvalidArgumentException('Street address contains invalid characters');
            }

            $user->setStreet($street);
        }

        if (isset($addressData['city'])) {
            $city = trim($addressData['city']);

            if (strlen($city) < 2) {
                throw new \InvalidArgumentException('City name is too short');
            }

            if (strlen($city) > 50) {
                throw new \InvalidArgumentException('City name cannot exceed 50 characters');
            }

            if (!preg_match('/^[a-zA-Z\s\-\']+$/', $city)) {
                throw new \InvalidArgumentException('City name contains invalid characters');
            }

            $user->setCity($city);
        }

        if (isset($addressData['postal_code'])) {
            $postalCode = trim($addressData['postal_code']);

            if (!preg_match('/^[A-Z0-9\s\-]{3,10}$/i', $postalCode)) {
                throw new \InvalidArgumentException('Invalid postal code format');
            }

            $user->setPostalCode($postalCode);
        }

        if (isset($addressData['country'])) {
            $country = trim($addressData['country']);

            if (strlen($country) !== 2) {
                throw new \InvalidArgumentException('Country must be a 2-letter code');
            }

            if (!preg_match('/^[A-Z]{2}$/', $country)) {
                throw new \InvalidArgumentException('Invalid country code format');
            }

            $user->setCountry($country);
        }

        $this->userRepository->save($user);

        return $user;
    }
}
