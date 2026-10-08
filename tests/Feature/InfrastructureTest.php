<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgis_extension_is_installed_by_migrations(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostGIS needs PostgreSQL. Run composer test:pgsql.');
        }

        $version = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'postgis'");
        $this->assertNotNull($version, 'postgis extension missing');

        $point = DB::selectOne("SELECT ST_AsText('SRID=4326;POINT(69.2401 41.2995)'::geography) AS wkt");
        $this->assertSame('POINT(69.2401 41.2995)', $point->wkt);
    }

    public function test_redis_backs_cache_and_queue(): void
    {
        if (config('cache.default') !== 'redis') {
            $this->markTestSkipped('Cache is not Redis in this run. Run composer test:pgsql.');
        }

        Cache::put('phase1-probe', 'ok', 30);
        $this->assertSame('ok', Cache::get('phase1-probe'));
        Cache::forget('phase1-probe');

        $queue = Queue::connection('redis');
        Redis::connection('default')->del('queues:phase1-probe');
        $queue->pushRaw(json_encode(['probe' => true]), 'phase1-probe');
        $this->assertSame(1, $queue->size('phase1-probe'));
        Redis::connection('default')->del('queues:phase1-probe');
    }
}
