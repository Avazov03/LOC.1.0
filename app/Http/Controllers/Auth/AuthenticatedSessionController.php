<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ActiveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /** Session key of a user who passed the password step and still owes the second factor. */
    public const PENDING_KEY = 'login.two_factor';

    /** The second step has to follow the password within this many seconds. */
    private const PENDING_SECONDS = 300;

    private const CHALLENGE_ATTEMPTS = 5;

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->authenticate();

        if ($user->hasTwoFactor()) {
            $request->session()->regenerate();
            $request->session()->put(self::PENDING_KEY, ['id' => $user->id, 'at' => now()->getTimestamp()]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->signIn($request, $user, $audit, []);
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verifyChallenge(Request $request, TwoFactorService $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('login')->with('error', 'Kirish vaqti tugadi. Login va parolni qayta kiriting.');
        }

        $key = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::CHALLENGE_ATTEMPTS)) {
            $request->session()->forget(self::PENDING_KEY);

            return redirect()->route('login')->with('error', 'Juda ko‘p noto‘g‘ri kod. '.RateLimiter::availableIn($key).' soniyadan keyin qayta kiring.');
        }

        $method = $twoFactor->verify($user, $data['code']);
        if ($method === null) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['code' => 'Kod noto‘g‘ri yoki eskirgan.']);
        }
        RateLimiter::clear($key);
        $request->session()->forget(self::PENDING_KEY);

        return $this->signIn($request, $user, $audit, ['two_factor' => $method]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function signIn(Request $request, User $user, AuditLogger $audit, array $metadata): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        $audit->log($user, 'auth.login', $user, metadata: ['role' => $user->role->value, ...$metadata]);

        return redirect()->intended(route('dashboard'));
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! is_array($pending) || now()->getTimestamp() - (int) ($pending['at'] ?? 0) > self::PENDING_SECONDS) {
            return null;
        }
        $user = User::query()->find((int) ($pending['id'] ?? 0));

        return $user !== null && $user->status === ActiveStatus::Active && $user->hasTwoFactor() ? $user : null;
    }
}
