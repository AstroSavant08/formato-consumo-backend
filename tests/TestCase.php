<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function authenticateApiUser(array $overrides = []): User
    {
        $user = User::factory()->create($overrides);
        Sanctum::actingAs($user);

        return $user;
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
