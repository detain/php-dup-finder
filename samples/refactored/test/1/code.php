<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class DashboardTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    abstract protected function route(): string;
    abstract protected function role(): string;

    /** @return array<string, mixed> */
    abstract protected function expectedDataShape(): array;

    protected function assertDashboardShape(): TestResponse
    {
        $user = User::factory()->create([
            'email' => $this->role() . '@example.com',
            'role'  => $this->role(),
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->get($this->route());

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => $this->expectedDataShape(),
            'meta' => ['generatedAt', 'version'],
        ]);

        return $response;
    }

    public function testGuestsAreDenied(): void
    {
        $this->getJson($this->route())->assertStatus(401);
    }
}

final class AdminDashboardTest extends DashboardTestCase
{
    protected function route(): string { return '/api/dashboard/admin'; }
    protected function role(): string  { return 'admin'; }
    protected function expectedDataShape(): array
    {
        return ['totalUsers', 'activeSessions', 'pendingTickets', 'systemHealth' => ['cpu', 'memory', 'disk']];
    }

    public function testReturnsExpectedShape(): void
    {
        $payload = $this->assertDashboardShape()->json('data');
        $this->assertIsInt($payload['totalUsers']);
    }
}
