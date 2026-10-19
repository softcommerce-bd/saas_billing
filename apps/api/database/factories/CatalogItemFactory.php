<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\CatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogItem>
 */
class CatalogItemFactory extends Factory
{
    protected $model = CatalogItem::class;

    public function definition(): array
    {
        $service = fake()->boolean();

        return [
            'type' => $service ? 'service' : 'product',
            'name' => $service ? fake()->randomElement(['Website maintenance', 'Consulting', 'Design work']) : fake()->words(2, true),
            'unit' => $service ? 'hour' : 'piece',
            'default_price_minor' => fake()->numberBetween(100, 50000) * 100,
            'is_active' => true,
        ];
    }
}
