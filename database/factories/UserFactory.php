<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    // Password default yang digunakan selama seeding
    protected static ?string $password;

    public function definition(): array
    {
        $isPremium = fake()->boolean(25);

        return [
            'name'                => fake()->name(),
            'email'               => fake()->unique()->safeEmail(),
            'phone_number'        => fake()->numerify('08##########'),
            'email_verified_at'   => now(),
            'password'            => static::$password ??= Hash::make('password'),
            'remember_token'      => Str::random(10),
            'badge_level'         => fake()->randomElement(['bronze', 'silver', 'gold', 'platinum']),
            'contribution_points' => fake()->numberBetween(0, 5000),
            'is_premium'          => $isPremium,
            'premium_expires_at'  => $isPremium
                ? fake()->dateTimeBetween('now', '+1 year')
                : null,
        ];
    }

    // User dengan email belum terverifikasi
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    // Badge level: bronze — pengguna baru
    public function bronze(): static
    {
        return $this->state(fn (array $attributes) => ['badge_level' => 'bronze']);
    }

    // Badge level: silver — pengguna aktif
    public function silver(): static
    {
        return $this->state(fn (array $attributes) => ['badge_level' => 'silver']);
    }

    // Badge level: gold — kontributor laporan
    public function gold(): static
    {
        return $this->state(fn (array $attributes) => ['badge_level' => 'gold']);
    }

    // Badge level: platinum — kontributor terverifikasi
    public function platinum(): static
    {
        return $this->state(fn (array $attributes) => ['badge_level' => 'platinum']);
    }
}

