<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RegionSeeder::class);
    }

    public function testStoreCreatesCustomer(): void
    {
        $user = User::factory()->create(['role' => 'sales']);

        $payload = [
            'company_name' => 'Acme Co.',
            'email'        => 'contact@acme.test',
            'phone'        => '+1-555-0100',
            'region_id'    => 1,
            'is_active'    => true,
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/customers', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'company_name', 'email', 'phone', 'region_id', 'created_at'],
        ]);
        $response->assertJsonPath('data.email', 'contact@acme.test');

        $this->assertDatabaseHas('customers', [
            'company_name' => 'Acme Co.',
            'email'        => 'contact@acme.test',
            'phone'        => '+1-555-0100',
        ]);
    }

    public function testStoreValidatesRequiredFields(): void
    {
        $user = User::factory()->create(['role' => 'sales']);
        $response = $this->actingAs($user)->postJson('/api/customers', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['company_name', 'email', 'region_id']);
    }
}
