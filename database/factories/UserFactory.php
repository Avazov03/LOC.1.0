<?php

namespace Database\Factories;

use App\Enums\ActiveStatus;
use App\Enums\UserRole;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'login' => fake()->unique()->userName(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Admin,
            'status' => ActiveStatus::Active,
            'university_id' => University::factory(),
        ];
    }

    public function supervisor(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Supervisor,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => ActiveStatus::Inactive,
        ]);
    }
}
