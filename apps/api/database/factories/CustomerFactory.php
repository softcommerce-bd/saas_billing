<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['individual', 'business']),
            'name' => fake()->name(),
            'company_name' => fake()->optional()->company(),
            'mobile' => '+8801'.fake()->randomElement(['3', '5', '6', '7', '8', '9']).fake()->numerify('########'),
            'email' => fake()->optional()->safeEmail(),
            'district' => 'Dhaka',
            'status' => 'active',
            'preferred_language' => 'bn',
        ];
    }
}
