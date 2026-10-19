<?php

namespace Tests;

use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\InteractsWithTenants;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = PlatformSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Behave like the first-party SPA so Sanctum starts a session (stateful domain).
        $this->withHeader('Origin', 'http://localhost:3000');
        $this->withHeader('Referer', 'http://localhost:3000/');
    }

    /**
     * Allow switching the acting user between requests within one test.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::be($user, $guard);
    }
}
