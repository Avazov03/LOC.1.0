<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Outside the web group: no session, cookies or CSRF. The controller checks the secret header itself.
            Route::post('/telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');
            Route::get('/health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'role' => EnsureRole::class,
            'active' => EnsureActiveUser::class,
        ]);
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(BusinessRuleException::class);
        $exceptions->render(function (BusinessRuleException $exception, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        });
    })->create();
