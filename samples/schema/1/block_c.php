<?php

declare(strict_types=1);

namespace App\Api\Schema;

use OpenApi\Annotations as OA;

/**
 * OpenAPI schema for user registration.
 * This API schema definition is duplicated from:
 * - MySQL DDL: migrations/V1__create_users_table.sql
 * - Doctrine entity: src/Domain/User/Entity/User.php
 * - JSON Schema: schemas/user-registration.json
 *
 * @OA\Schema(
 *   schema="UserRegistration",
 *   required={"email", "password", "firstName", "lastName"},
 *   @OA\Property(property="email", type="string", format="email", maxLength=255),
 *   @OA\Property(property="password", type="string", minLength=8, maxLength=128),
 *   @OA\Property(property="firstName", type="string", maxLength=100),
 *   @OA\Property(property="lastName", type="string", maxLength=100),
 *   @OA\Property(property="phoneNumber", type="string", maxLength=20),
 *   @OA\Property(property="countryCode", type="string", maxLength=2, default="US"),
 *   @OA\Property(property="referralCode", type="string", maxLength=36),
 *   @OA\Property(property="preferences", type="object"),
 *   @OA\Property(property="organizationId", type="string"),
 * )
 *
 * @OA\Schema(
 *   schema="UserRegistrationResponse",
 *   @OA\Property(property="id", type="string"),
 *   @OA\Property(property="email", type="string"),
 *   @OA\Property(property="firstName", type="string"),
 *   @OA\Property(property="lastName", type="string"),
 *   @OA\Property(property="status", type="string"),
 *   @OA\Property(property="createdAt", type="string", format="date-time"),
 * )
 */
class UserRegistrationSchema
{
    public const SCHEMA_NAME = 'UserRegistration';
    public const SCHEMA_VERSION = '1.0.0';

    public static function getOpenApiSpec(): array
    {
        return [
            'type' => 'object',
            'required' => ['email', 'password', 'firstName', 'lastName'],
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'format' => 'uuid',
                    'description' => 'Unique user identifier',
                ],
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'maxLength' => 255,
                    'description' => 'User email address',
                ],
                'password' => [
                    'type' => 'string',
                    'minLength' => 8,
                    'maxLength' => 128,
                    'description' => 'User password (will be hashed)',
                ],
                'firstName' => [
                    'type' => 'string',
                    'maxLength' => 100,
                    'description' => 'User first name',
                ],
                'lastName' => [
                    'type' => 'string',
                    'maxLength' => 100,
                    'description' => 'User last name',
                ],
                'phoneNumber' => [
                    'type' => 'string',
                    'maxLength' => 20,
                    'nullable' => true,
                    'description' => 'Phone number in E.164 format',
                ],
                'countryCode' => [
                    'type' => 'string',
                    'maxLength' => 2,
                    'default' => 'US',
                    'description' => 'ISO 3166-1 alpha-2 country code',
                ],
                'referralCode' => [
                    'type' => 'string',
                    'maxLength' => 36,
                    'nullable' => true,
                    'description' => 'Referral code if user was referred',
                ],
                'preferences' => [
                    'type' => 'object',
                    'nullable' => true,
                    'description' => 'User preferences as key-value pairs',
                ],
                'organizationId' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Organization ID if user belongs to organization',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'active', 'suspended', 'deleted'],
                    'default' => 'pending',
                    'description' => 'User account status',
                ],
                'createdAt' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'Account creation timestamp',
                ],
                'emailVerifiedAt' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'nullable' => true,
                    'description' => 'Email verification timestamp',
                ],
            ],
        ];
    }
}
