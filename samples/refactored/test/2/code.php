<?php

declare(strict_types=1);

namespace Tests\Feature\Crud;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AbstractCreateResourceTest extends TestCase
{
    use RefreshDatabase;

    /** @return class-string */
    abstract protected function seederClass(): string;
    abstract protected function endpoint(): string;
    abstract protected function table(): string;
    abstract protected function role(): string;

    /** @return array<string, mixed> */
    abstract protected function validPayload(): array;

    /** @return list<string> */
    abstract protected function expectedDataKeys(): array;

    /** @return list<string> */
    abstract protected function requiredFields(): array;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed($this->seederClass());
    }

    public function testStoreCreatesResource(): void
    {
        $user = User::factory()->create(['role' => $this->role()]);
        $payload = $this->validPayload();

        $response = $this->actingAs($user)->postJson($this->endpoint(), $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure(['data' => $this->expectedDataKeys()]);
        $this->assertDatabaseHas($this->table(), $payload);
    }

    public function testStoreValidatesRequiredFields(): void
    {
        $user = User::factory()->create(['role' => $this->role()]);
        $response = $this->actingAs($user)->postJson($this->endpoint(), []);
        $response->assertStatus(422)->assertJsonValidationErrors($this->requiredFields());
    }
}
