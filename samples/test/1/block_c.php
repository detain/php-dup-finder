<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function testAnalyticsDashboardReturnsExpectedShape(): void
    {
        $user = User::factory()->create([
            'email' => 'analyst@example.com',
            'role'  => 'analyst',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/api/dashboard/analytics');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'pageViews',
                'uniqueVisitors',
                'conversionRate',
                'topPages' => ['*' => ['url', 'views']],
            ],
            'meta' => ['generatedAt', 'version'],
        ]);

        $payload = $response->json('data');
        $this->assertIsInt($payload['pageViews']);
        $this->assertGreaterThanOrEqual(0, $payload['uniqueVisitors']);
        $this->assertIsArray($payload['topPages']);
    }

    public function testAnalyticsDashboardDeniesGuests(): void
    {
        $response = $this->getJson('/api/dashboard/analytics');
        $response->assertStatus(401);
    }
}
