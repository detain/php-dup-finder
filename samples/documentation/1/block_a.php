<?php

declare(strict_types=1);

namespace App\Api\Controllers\User;

use App\Domain\UserManagement\Entity\User;
use App\Domain\UserManagement\Repository\UserRepositoryInterface;
use App\Application\DTOs\User\UserRegistrationRequest;
use App\Application\DTOs\User\UserProfileUpdateRequest;
use App\Application\Services\UserService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * User management controller handling registration, authentication,
 * profile management, and user lifecycle operations.
 *
 * @Route("/api/v1/users", name="api_v1_users_")
 * @OA\Tag(name="Users", description="User management and authentication")
 */
class UserController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Register a new user account with email verification.
     *
     * @param UserRegistrationRequest $request The validated registration data
     *   - email: string (required) User's email address, must be unique
     *   - password: string (required) Minimum 8 characters, must contain uppercase,
     *                lowercase, and numeric characters
     *   - firstName: string (required) User's first name, 1-50 characters
     *   - lastName: string (required) User's last name, 1-50 characters
     *   - phoneNumber: string (optional) E.164 formatted phone number
     *   - dateOfBirth: string (optional) ISO 8601 date format YYYY-MM-DD
     *   - marketingOptIn: bool (optional, default false) Consent for marketing emails
     * @return JsonResponse 201 on success with userId and verificationRequired flag
     *   - userId: string UUID of newly created user
     *   - verificationRequired: bool Always true on successful registration
     *   - emailVerificationToken: string Token sent to email for verification
     * @throws ValidationException 422 if email already exists or password too weak
     * @throws DomainException 400 if email domain is blocked (disposable email)
     *
     * @OA\Post(
     *   path="/api/v1/users",
     *   summary="Register a new user",
     *   tags={"Users"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email", "password", "firstName", "lastName"},
     *       @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *       @OA\Property(property="password", type="string", minLength=8, example="SecurePass123"),
     *       @OA\Property(property="firstName", type="string", minLength=1, maxLength=50, example="John"),
     *       @OA\Property(property="lastName", type="string", minLength=1, maxLength=50, example="Doe"),
     *       @OA\Property(property="phoneNumber", type="string", example="+14155551234"),
     *       @OA\Property(property="dateOfBirth", type="string", format="date", example="1990-01-15"),
     *       @OA\Property(property="marketingOptIn", type="boolean", example=false)
     *     )
     *   ),
     *   @OA\Response(response=201, description="User registered successfully"),
     *   @OA\Response(response=422, description="Validation error - email exists or invalid password"),
     *   @OA\Response(response=400, description="Blocked email domain")
     * )
     */
    #[Route('', name: 'register', methods: ['POST'])]
    public function register(UserRegistrationRequest $request): JsonResponse
    {
        $this->logger->info('User registration attempt', [
            'email' => $request->getEmail(),
            'ip_address' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
        ]);

        try {
            $result = $this->userService->register($request->toDomainCommand());

            $this->logger->notice('User registered successfully', [
                'user_id' => $result->userId->toString(),
                'email' => $request->getEmail(),
            ]);

            return new JsonResponse([
                'userId' => $result->userId->toString(),
                'verificationRequired' => true,
                'emailVerificationToken' => $result->emailVerificationToken,
            ], Response::HTTP_CREATED);

        } catch (EmailAlreadyExistsException $e) {
            $this->logger->warning('Registration failed - email exists', [
                'email' => $request->getEmail(),
            ]);
            return new JsonResponse([
                'error' => 'email_already_exists',
                'message' => 'A user with this email address already exists',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (WeakPasswordException $e) {
            $this->logger->warning('Registration failed - weak password', [
                'email' => $request->getEmail(),
                'password_score' => $e->getStrengthScore(),
            ]);
            return new JsonResponse([
                'error' => 'weak_password',
                'message' => 'Password does not meet security requirements',
                'requirements' => [
                    'min_length' => 8,
                    'require_uppercase' => true,
                    'require_lowercase' => true,
                    'require_numeric' => true,
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (BlockedEmailDomainException $e) {
            $this->logger->error('Registration failed - blocked domain', [
                'domain' => $e->getDomain(),
            ]);
            return new JsonResponse([
                'error' => 'blocked_email_domain',
                'message' => 'This email provider is not allowed',
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
