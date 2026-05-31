<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateWarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CountrySeeder::class);
    }

    public function testStoreCreatesWarehouse(): void
    {
        $user = User::factory()->create(['role' => 'ops']);

        $payload = [
            'code'        => 'WHX-7',
            'name'        => 'Western Hub',
            'capacity'    => 25000,
            'country_id'  => 1,
            'is_primary'  => false,
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/warehouses', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'code', 'name', 'capacity', 'country_id', 'created_at'],
        ]);
        $response->assertJsonPath('data.code', 'WHX-7');

        $this->assertDatabaseHas('warehouses', [
            'code'     => 'WHX-7',
            'name'     => 'Western Hub',
            'capacity' => 25000,
        ]);
    }

    public function testStoreValidatesRequiredFields(): void
    {
        $user = User::factory()->create(['role' => 'ops']);
        $response = $this->actingAs($user)->postJson('/api/warehouses', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code', 'name', 'capacity']);
    }
}
