<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ActiveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $ok = Auth::attempt([
            'login' => $request->string('login')->toString(),
            'password' => $request->string('password')->toString(),
            'status' => ActiveStatus::Active->value,
        ], false);

        if (! $ok) {
            return back()->withErrors([
                'login' => 'Login yoki parol noto‘g‘ri, yoki hisob faol emas.',
            ])->onlyInput('login');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
