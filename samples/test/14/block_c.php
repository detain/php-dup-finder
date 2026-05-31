<?php

declare(strict_types=1);

namespace Tests\Shared\Fixtures;

trait ModelFactoriesTrait
{
    protected function mergeDefaults(array $defaults, array $overrides): array
    {
        return array_merge($defaults, $overrides);
    }

    protected function createUserFactory(array $overrides = []): array
    {
        return $this->mergeDefaults([
            'email' => 'testuser@example.com',
            'password' => 'SecurePass123!',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+1-555-123-4567',
            'timezone' => 'America/New_York',
            'locale' => 'en_US',
            'is_active' => true,
            'email_verified_at' => null,
            'organization_id' => null,
            'metadata' => [],
        ], $overrides);
    }

    protected function createProductFactory(array $overrides = []): array
    {
        return $this->mergeDefaults([
            'sku' => 'PROD-' . uniqid(),
            'name' => 'Test Product',
            'description' => 'A test product description',
            'price' => 2999,
            'cost' => 1500,
            'currency' => 'USD',
            'tax_category' => 'standard',
            'inventory_tracked' => true,
            'inventory_count' => 100,
            'status' => 'active',
            'metadata' => [],
        ], $overrides);
    }

    protected function createOrganizationFactory(array $overrides = []): array
    {
        return $this->mergeDefaults([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'status' => 'active',
            'plan' => 'enterprise',
            'max_users' => 100,
            'settings' => [],
        ], $overrides);
    }

    protected function createCategoryFactory(array $overrides = []): array
    {
        return $this->mergeDefaults([
            'name' => 'Category',
            'slug' => 'category',
            'is_active' => true,
            'sort_order' => 0,
            'metadata' => [],
        ], $overrides);
    }
}
