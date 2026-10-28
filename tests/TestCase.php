<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        if ($guard === null && $user instanceof User && $user->department === null) {
            config(['vault.admin.email' => $user->email]);
        }

        return parent::actingAs($user, $guard);
    }
}
