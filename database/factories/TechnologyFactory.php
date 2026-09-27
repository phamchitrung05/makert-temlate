<?php

namespace Database\Factories;

use App\Enums\TechnologyType;
use App\Models\Technology;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Technology>
 */
class TechnologyFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Technology>
     */
    protected $model = Technology::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'type' => fake()->randomElement(TechnologyType::values()),
        ];
    }
}
