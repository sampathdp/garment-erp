<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Organizations\Enums\PagePlatform;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->lexify('PG???'));

        return [
            'organization_id' => Organization::factory(),
            'code' => $code,
            'name' => fake()->unique()->words(2, true).' Jerseys',
            'platform' => PagePlatform::Facebook,
            'invoice_prefix' => $code,
            'invoice_header_name' => fake()->company(),
            'is_active' => true,
        ];
    }
}
