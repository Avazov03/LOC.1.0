<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Rate-limiter keys outlive a test in Redis; phpunit.pgsql.xml points the cache at a test-only DB.
        if (config('cache.default') === 'redis' && app()->environment('testing')) {
            Cache::flush();
        }
    }
}
