<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected bool $autoAuthenticateApi = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->autoAuthenticateApi) {
            $this->authenticateApiUser();
        }
    }

    protected function authenticateApiUser(array $overrides = []): User
    {
        $this->seedRoles();
        $supervisorRole = Role::query()->where('nombre', Role::SUPERVISOR)->firstOrFail();

        $user = User::factory()->create(array_merge([
            'role_id' => $supervisorRole->id,
        ], $overrides));
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withEntregaPersona(array $payload): array
    {
        return array_merge([
            'quien_retira_cedula' => '1107085013',
            'quien_retira_nombre' => 'MONTENEGRO VARA YOINER ALEXIS',
        ], $payload);
    }

    protected function clearAuthentication(): void
    {
        auth('sanctum')->forgetUser();
    }

    protected function seedRoles(): void
    {
        $this->seed(RoleSeeder::class);
    }

    protected function createUserWithRole(string $roleName, array $overrides = []): User
    {
        $this->seedRoles();

        $role = Role::query()->where('nombre', $roleName)->firstOrFail();

        return User::factory()->create(array_merge(['role_id' => $role->id], $overrides));
    }
}
