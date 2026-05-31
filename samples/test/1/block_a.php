<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function testAdminDashboardReturnsExpectedShape(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role'  => 'admin',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/api/dashboard/admin');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'totalUsers',
                'activeSessions',
                'pendingTickets',
                'systemHealth' => ['cpu', 'memory', 'disk'],
            ],
            'meta' => ['generatedAt', 'version'],
        ]);

        $payload = $response->json('data');
        $this->assertIsInt($payload['totalUsers']);
        $this->assertGreaterThanOrEqual(0, $payload['activeSessions']);
        $this->assertArrayHasKey('cpu', $payload['systemHealth']);
    }

    public function testAdminDashboardDeniesGuests(): void
    {
        $response = $this->getJson('/api/dashboard/admin');
        $response->assertStatus(401);
    }
}
