<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'ime'      => $this->faker->firstName(),
            'prezime'  => $this->faker->lastName(),
            'email'    => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('tajna123'),
            'role'     => 'clan',
            'telefon'  => '+381' . $this->faker->numerify('6########'),
            'adresa'   => $this->faker->address(),
        ];
    }

    // State metoda za admin
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    // State metoda za bibliotekara — OVO JE BILO ZAKOMENTARISANO
    public function bibliotekar(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'bibliotekar',
        ]);
    }

    // State metoda za člana sa karticom
    public function clan(string $brKarte): static
    {
        return $this->state(fn (array $attributes) => [
            'role'               => 'clan',
            'broj_clanske_karte' => $brKarte,
            'password'           => Hash::make('clan123'),
        ]);
    }
}