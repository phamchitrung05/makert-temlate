<?php

namespace Database\Factories;

use App\Models\Resource;
use App\Models\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Slug>
 */
class SlugFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Slug>
     */
    protected $model = Slug::class;

    /**
     * Define the model's default state.
     *
     * Bản ghi Resource đi kèm không dùng `Resource::factory()` vì Resource đã
     * tự sinh slug qua trait `HasSlug`. Nếu dùng factory sẽ có hai bản ghi
     * slug trùng nhau và vi phạm unique index trên
     * (sluggable_type, slug, locale).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sluggable_type' => (new Resource)->getMorphClass(),
            'sluggable_id' => Resource::factory(),
            'slug' => Str::slug(fake()->unique()->words(3, true)),
            'locale' => config('app.locale'),
            'is_primary' => true,
        ];
    }
}
