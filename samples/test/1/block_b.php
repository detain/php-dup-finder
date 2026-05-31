<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BillingDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function testBillingDashboardReturnsExpectedShape(): void
    {
        $user = User::factory()->create([
            'email' => 'billing@example.com',
            'role'  => 'billing_manager',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/api/dashboard/billing');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'monthlyRevenue',
                'outstandingInvoices',
                'churnRate',
                'subscriptions' => ['active', 'paused', 'cancelled'],
            ],
            'meta' => ['generatedAt', 'version'],
        ]);

        $payload = $response->json('data');
        $this->assertIsNumeric($payload['monthlyRevenue']);
        $this->assertGreaterThanOrEqual(0, $payload['outstandingInvoices']);
        $this->assertArrayHasKey('active', $payload['subscriptions']);
    }

    public function testBillingDashboardDeniesGuests(): void
    {
        $response = $this->getJson('/api/dashboard/billing');
        $response->assertStatus(401);
    }
}
