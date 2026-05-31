<?php

declare(strict_types=1);

/**
 * MySQL database table definition for user registration data.
 * This is the canonical database schema, duplicated in Doctrine entity,
 * API schema, and JSON schema for user registration.
 *
 * @Table(name="users")
 * Primary key: id (UUID)
 * Indexes: idx_email (unique), idx_status, idx_created_at
 * Constraints: email must be unique, validated format
 *
 * DOCUMENTED IN:
 * - Database migrations: migrations/V1__create_users_table.sql
 * - Entity: src/Domain/User/Entity/User.php
 * - API spec: paths./users.register
 * - JSON Schema: schemas/user-registration.json
 */
class UserSchema
{
    /**
     * @Column(type="string", length=36)
     */
    private string $id;

    /**
     * @Column(type="string", length=255, unique=true)
     */
    private string $email;

    /**
     * @Column(type="string", length=255)
     */
    private string $passwordHash;

    /**
     * @Column(type="string", length=100)
     */
    private string $firstName;

    /**
     * @Column(type="string", length=100)
     */
    private string $lastName;

    /**
     * @Column(type="string", length=20, nullable=true)
     */
    private ?string $phoneNumber = null;

    /**
     * @Column(type="string", length=2)
     */
    private string $countryCode = 'US';

    /**
     * @Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /**
     * @Column(type="string", length=20)
     */
    private string $status = 'pending';

    /**
     * @Column(type="string", length=36, nullable=true)
     */
    private ?string $referralCode = null;

    /**
     * @Column(type="json", nullable=true)
     */
    private ?array $preferences = null;

    /**
     * @Column(type="string", length=36, nullable=true)
     */
    private ?string $organizationId = null;
}
