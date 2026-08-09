<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'nickname' => fake()->name(),
            'phone' => null,
            'phone_hash' => null,
            'phone_verified_at' => null,
            'profile_completed_at' => null,
            'status' => 'active',
        ];
    }
}
