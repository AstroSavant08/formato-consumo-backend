<?php

namespace Tests;

use App\Models\User;
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
}
