<?php

declare(strict_types=1);

namespace App\Services\Grpc;

use App\Services\UserService;
use App\Grpc\UserServiceInterface;
use App\Grpc\UserRequest;
use App\Grpc\UserResponse;
use App\Grpc\UserListResponse;
use App\Grpc\EmptyRequest;
use Spiral\GRPC;
use Spiral\GRPC\Exception\NotFoundException;
use Spiral\GRPC\Exception\BadRequestException;

class UserGrpcService implements UserServiceInterface
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function GetUser(GRPC\ContextInterface $ctx, UserRequest $in): UserResponse
    {
        // Validate request
        if (!$in->getId()) {
            throw new BadRequestException('User ID is required');
        }

        // Get user
        $user = $this->userService->findById($in->getId());

        if (!$user) {
            throw new NotFoundException('User not found');
        }

        // Build response
        return new UserResponse([
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'status' => $user['status'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
        ]);
    }

    public function ListUsers(GRPC\ContextInterface $ctx, UserListRequest $in): UserListResponse
    {
        // Parse pagination
        $limit = $in->getLimit() ?: 50;
        $offset = $in->getOffset() ?: 0;

        // Get filters
        $filters = [];
        if ($in->getStatus()) {
            $filters['status'] = $in->getStatus();
        }
        if ($in->getOrganizationId()) {
            $filters['organization_id'] = $in->getOrganizationId();
        }

        // Get users
        $users = $this->userService->searchUsers('', $filters, $limit, $offset);
        $total = $this->userService->countSearchResults('', $filters);

        // Build response
        $userProtos = array_map(function ($user) {
            return new UserResponse([
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'status' => $user['status'],
            ]);
        }, $users);

        return new UserListResponse([
            'users' => $userProtos,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function CreateUser(GRPC\ContextInterface $ctx, CreateUserRequest $in): UserResponse
    {
        // Validate request
        $validationErrors = [];

        if (!$in->getEmail()) {
            $validationErrors[] = 'Email is required';
        } elseif (!filter_var($in->getEmail(), FILTER_VALIDATE_EMAIL)) {
            $validationErrors[] = 'Invalid email format';
        }

        if (!$in->getName()) {
            $validationErrors[] = 'Name is required';
        }

        if (!$in->getPassword()) {
            $validationErrors[] = 'Password is required';
        } elseif (strlen($in->getPassword()) < 8) {
            $validationErrors[] = 'Password must be at least 8 characters';
        }

        if (!empty($validationErrors)) {
            throw new BadRequestException(implode(', ', $validationErrors));
        }

        // Create user
        $userData = [
            'email' => $in->getEmail(),
            'name' => $in->getName(),
            'password' => $in->getPassword(),
            'phone' => $in->getPhone(),
            'timezone' => $in->getTimezone() ?: 'UTC',
        ];

        try {
            $user = $this->userService->createUser($userData);
        } catch (\App\Exceptions\EmailAlreadyExistsException $e) {
            throw new BadRequestException('Email already exists');
        }

        return new UserResponse([
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'status' => $user['status'],
        ]);
    }

    public function UpdateUser(GRPC\ContextInterface $ctx, UpdateUserRequest $in): UserResponse
    {
        // Validate request
        if (!$in->getId()) {
            throw new BadRequestException('User ID is required');
        }

        $user = $this->userService->findById($in->getId());
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        // Build update data
        $updateData = [];
        if ($in->getEmail()) {
            $updateData['email'] = $in->getEmail();
        }
        if ($in->getName()) {
            $updateData['name'] = $in->getName();
        }
        if ($in->getPhone()) {
            $updateData['phone'] = $in->getPhone();
        }
        if ($in->getTimezone()) {
            $updateData['timezone'] = $in->getTimezone();
        }

        $updatedUser = $this->userService->updateUser($in->getId(), $updateData);

        return new UserResponse([
            'id' => $updatedUser['id'],
            'email' => $updatedUser['email'],
            'name' => $updatedUser['name'],
            'status' => $updatedUser['status'],
        ]);
    }

    public function DeleteUser(GRPC\ContextInterface $ctx, UserRequest $in): EmptyResponse
    {
        if (!$in->getId()) {
            throw new BadRequestException('User ID is required');
        }

        $user = $this->userService->findById($in->getId());
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        $this->userService->deleteUser($in->getId());

        return new EmptyResponse();
    }
}
