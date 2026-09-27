<?php

namespace Database\Factories;

use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVisibility;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<resource>
 */
class ResourceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<resource>
     */
    protected $model = Resource::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->catchPhrase();

        return [
            'type' => fake()->randomElement(ResourceType::values()),
            'title' => $title,
            'code' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 999999),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraphs(3, true),
            'status' => ResourceStatus::Draft,
            'visibility' => ResourceVisibility::Public,
            'is_featured' => false,
            'view_count' => 0,
            'download_count' => 0,
        ];
    }

    /**
     * Indicate that the resource is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ResourceStatus::Published,
            'visibility' => ResourceVisibility::Public,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the resource is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ResourceStatus::Draft,
        ]);
    }
}
