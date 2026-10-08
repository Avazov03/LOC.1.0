<?php

namespace App\Services\Admin;

use App\Enums\ActiveStatus;
use App\Enums\UserRole;
use App\Models\University;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates or refreshes the first ADMIN from environment values, so no credential lives in source or Git.
 */
class BootstrapAdminService
{
    public function ensure(?string $login = null, ?string $password = null, ?string $name = null): User
    {
        $config = config('app.bootstrap_admin');
        $login = trim((string) ($login ?? $config['login']));
        $password = (string) ($password ?? $config['password']);
        $name = trim((string) ($name ?? $config['name'])) ?: 'Administrator';

        if ($login === '' || ! preg_match('/^[A-Za-z0-9._-]{3,64}$/', $login)) {
            throw new InvalidArgumentException('ADMIN_LOGIN is missing or invalid (3–64 characters: letters, digits, . _ -).');
        }
        if (mb_strlen($password) < 8) {
            throw new InvalidArgumentException('ADMIN_PASSWORD is missing or shorter than 8 characters.');
        }

        return DB::transaction(function () use ($config, $login, $password, $name) {
            $universityName = trim((string) $config['university_name']) ?: 'Universitet';
            $university = University::query()->orderBy('id')->first() ?? University::query()->create([
                'name' => $universityName,
                'slug' => Str::slug($universityName) ?: 'universitet',
                'timezone' => $config['university_timezone'] ?: 'Asia/Tashkent',
            ]);

            $user = User::query()->where('login', $login)->first() ?? new User(['login' => $login, 'university_id' => $university->id]);
            if ($user->exists && $user->role !== UserRole::Admin) {
                throw new InvalidArgumentException("Login {$login} already belongs to a non-admin account.");
            }

            $user->fill([
                'name' => $user->exists ? $user->name : $name,
                'email' => $user->email ?? ($config['email'] ?: null),
                'password' => $password,
                'role' => UserRole::Admin,
                'status' => ActiveStatus::Active,
            ])->save();

            return $user;
        });
    }
}
