<?php

namespace App\Providers;

use App\Policies\AcademicPolicy;
use App\Telegram\FakeTelegramClient;
use App\Telegram\HttpTelegramClient;
use App\Telegram\TelegramClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TelegramClient::class, function ($app) {
            if ($app->runningUnitTests() || config('services.telegram.fake')) {
                return new FakeTelegramClient;
            }

            return new HttpTelegramClient(
                config('services.telegram.bot_token'),
                (string) config('services.telegram.api_base'),
                (int) config('services.telegram.timeout'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('academic.manage', [AcademicPolicy::class, 'manage']);

        // §61: change requests are rate-limited per signed-in user (A61).
        RateLimiter::for('change-requests', fn (Request $request) => Limit::perMinute(10)->by('change-requests:'.$request->user()?->id));
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(5)->by('exports:'.$request->user()?->id));

        // JIT compilation costs ~0.5 s on the report queries and saves nothing on these short index-driven plans
        // (measured at 1,000 students: 590 ms with JIT, 95 ms without).
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            if ($event->connection->getDriverName() === 'pgsql') {
                $event->connection->statement('SET jit = off');
            }
        });

        $proxies = trim((string) config('app.trusted_proxies'));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}
