<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CategorySeeder::class);
    }

    public function testStoreCreatesProduct(): void
    {
        $user = User::factory()->create(['role' => 'merchant']);

        $payload = [
            'sku'         => 'PRD-001',
            'name'        => 'Widget',
            'price_cents' => 1999,
            'category_id' => 1,
            'in_stock'    => true,
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/products', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'sku', 'name', 'price_cents', 'category_id', 'created_at'],
        ]);
        $response->assertJsonPath('data.sku', 'PRD-001');

        $this->assertDatabaseHas('products', [
            'sku'         => 'PRD-001',
            'name'        => 'Widget',
            'price_cents' => 1999,
        ]);
    }

    public function testStoreValidatesRequiredFields(): void
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $response = $this->actingAs($user)->postJson('/api/products', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sku', 'name', 'price_cents']);
    }
}
