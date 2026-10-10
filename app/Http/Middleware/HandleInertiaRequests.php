<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ImpersonationController;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user instanceof User ? [
                    'name' => $user->name,
                    'login' => $user->login,
                    'role' => $user->role->value,
                    'university' => $user->university?->name,
                ] : null,
                'impersonating' => $user instanceof User && $request->session()->has(ImpersonationController::SESSION_KEY),
            ],
            'navigation' => $user instanceof User ? Navigation::for($user) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'invite_link' => fn () => $request->session()->get('invite_link'),
                'telegram_link' => fn () => $request->session()->get('telegram_link'),
                'assignment_results' => fn () => $request->session()->get('assignment_results'),
                'recovery_codes' => fn () => $request->session()->get('recovery_codes'),
            ],
            'appName' => config('app.name'),
        ];
    }
}
