<?php

namespace App\Http\Requests\Auth;

use App\Enums\ActiveStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Checks the login and password. The caller signs the user in, or first asks for the second factor.
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $user = User::query()
            ->where('login', $this->string('login')->toString())
            ->whereIn('role', [UserRole::Admin->value, UserRole::Supervisor->value])
            ->where('status', ActiveStatus::Active->value)
            ->first();

        if ($user === null || $user->password === null || ! Hash::check($this->string('password')->toString(), $user->password)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => 'Login yoki parol noto‘g‘ri, yoki hisob faol emas.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => "Juda ko‘p urinish. {$seconds} soniyadan keyin qayta urinib ko‘ring.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')->toString()).'|'.$this->ip());
    }
}
