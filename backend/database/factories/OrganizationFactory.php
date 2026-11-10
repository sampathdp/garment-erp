<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' (Pvt) Ltd',
            'default_currency' => 'LKR',
        ];
    }
}
