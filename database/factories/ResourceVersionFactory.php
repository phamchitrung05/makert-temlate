<?php

namespace Database\Factories;

use App\Enums\ResourceVersionStatus;
use App\Models\Resource;
use App\Models\ResourceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceVersion>
 */
class ResourceVersionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ResourceVersion>
     */
    protected $model = ResourceVersion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'version' => fake()->unique()->numerify('1.#.#'),
            'changelog' => fake()->paragraph(),
            'requirements' => ['php' => '>=8.2'],
            'status' => ResourceVersionStatus::Draft,
            'is_default' => false,
        ];
    }
}
