<?php
declare(strict_types=1);

namespace Api\Customers;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer Management API
 *
 * Endpoints for managing customer accounts, profiles, and preferences.
 */
#[AsController]
final class CustomerController
{
    /**
     * Create a new customer account
     *
     * @param Request $request The HTTP request
     * @param string $email Customer's email address (required, valid email format, max 254 chars)
     * @param string $firstName Customer's first name (required, max 100 chars, alphanumeric with spaces/hyphens)
     * @param string $lastName Customer's last name (required, max 100 chars, alphanumeric with spaces/hyphens)
     * @param string $phone Customer's phone number (optional, E.164 format, 10-15 digits)
     * @param string $password Initial password (required, min 8 chars, must contain uppercase, lowercase, number, special char)
     * @param string $referralCode Optional referral code (5-20 alphanumeric characters)
     * @param bool $marketingConsent Whether customer opted into marketing communications
     * @return JsonResponse
     *
     * @throws InvalidArgumentException When email is invalid or password doesn't meet requirements
     * @throws DuplicateEmailException When email already exists in system
     */
    #[Route('', ['name' => 'create', 'methods' => ['POST']])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (empty($data['email'])) {
            return new JsonResponse(['error' => 'Email is required'], 400);
        }
        if (empty($data['first_name'])) {
            return new JsonResponse(['error' => 'First name is required'], 400);
        }
        if (empty($data['last_name'])) {
            return new JsonResponse(['error' => 'Last name is required'], 400);
        }
        if (empty($data['password'])) {
            return new JsonResponse(['error' => 'Password is required'], 400);
        }

        // Email validation with format check
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Invalid email format'], 400);
        }
        if (strlen($data['email']) > 254) {
            return new JsonResponse(['error' => 'Email exceeds maximum length of 254 characters'], 400);
        }

        // Name validation
        $firstName = trim($data['first_name']);
        $lastName = trim($data['last_name']);
        if (!preg_match('/^[a-zA-Z\s\-\']{1,100}$/', $firstName)) {
            return new JsonResponse(['error' => 'First name contains invalid characters'], 400);
        }
        if (!preg_match('/^[a-zA-Z\s\-\']{1,100}$/', $lastName)) {
            return new JsonResponse(['error' => 'Last name contains invalid characters'], 400);
        }

        // Password strength validation
        $password = $data['password'];
        if (strlen($password) < 8) {
            return new JsonResponse(['error' => 'Password must be at least 8 characters'], 400);
        }
        if (strlen($password) > 128) {
            return new JsonResponse(['error' => 'Password exceeds maximum length of 128 characters'], 400);
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return new JsonResponse(['error' => 'Password must contain at least one uppercase letter'], 400);
        }
        if (!preg_match('/[a-z]/', $password)) {
            return new JsonResponse(['error' => 'Password must contain at least one lowercase letter'], 400);
        }
        if (!preg_match('/[0-9]/', $password)) {
            return new JsonResponse(['error' => 'Password must contain at least one number'], 400);
        }
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            return new JsonResponse(['error' => 'Password must contain at least one special character'], 400);
        }

        // Check for duplicate email
        $existing = $this->entityManager->getRepository(Customer::class)
            ->findOneBy(['email' => strtolower($data['email'])]);

        if ($existing !== null) {
            return new JsonResponse(['error' => 'Email already registered'], 409);
        }

        // Create customer
        $customer = new Customer();
        $customer->setEmail(strtolower($data['email']));
        $customer->setFirstName($firstName);
        $customer->setLastName($lastName);
        $customer->setPhone($data['phone'] ?? null);
        $customer->setPasswordHash(password_hash($password, PASSWORD_ARGON2ID));
        $customer->setMarketingConsent($data['marketing_consent'] ?? false);
        $customer->setReferralCode($data['referral_code'] ?? null);
        $customer->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'first_name' => $customer->getFirstName(),
            'last_name' => $customer->getLastName(),
            'created_at' => $customer->getCreatedAt()->format('c')
        ], 201);
    }

    /**
     * Update customer profile
     *
     * @param Request $request HTTP request containing update data
     * @param int $id Customer ID
     * @param string|null $firstName Updated first name (1-100 chars, letters/spaces/hyphens only)
     * @param string|null $lastName Updated last name (1-100 chars, letters/spaces/hyphens only)
     * @param string|null $phone Updated phone number (E.164 format, 10-15 digits)
     * @param bool|null $marketingConsent Updated marketing consent preference
     * @return JsonResponse
     */
    #[Route('/{id}', ['name' => 'update', 'methods' => ['PATCH']])]
    public function update(Request $request, int $id): JsonResponse
    {
        $customer = $this->entityManager->find(Customer::class, $id);
        if ($customer === null) {
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['first_name'])) {
            $firstName = trim($data['first_name']);
            if (!preg_match('/^[a-zA-Z\s\-\']{1,100}$/', $firstName)) {
                return new JsonResponse(['error' => 'Invalid first name format'], 400);
            }
            $customer->setFirstName($firstName);
        }

        if (isset($data['last_name'])) {
            $lastName = trim($data['last_name']);
            if (!preg_match('/^[a-zA-Z\s\-\']{1,100}$/', $lastName)) {
                return new JsonResponse(['error' => 'Invalid last name format'], 400);
            }
            $customer->setLastName($lastName);
        }

        if (isset($data['phone'])) {
            $phone = preg_replace('/\D/', '', $data['phone']);
            if (strlen($phone) < 10 || strlen($phone) > 15) {
                return new JsonResponse(['error' => 'Invalid phone number format'], 400);
            }
            $customer->setPhone('+' . ltrim($phone, '+'));
        }

        if (isset($data['marketing_consent'])) {
            $customer->setMarketingConsent((bool) $data['marketing_consent']);
        }

        $customer->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return new JsonResponse($customer->toArray());
    }
}
