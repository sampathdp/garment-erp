<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => strtoupper(fake()->unique()->lexify('BR???')),
            'name' => fake()->unique()->city().' Branch',
            'is_active' => true,
        ];
    }
}
